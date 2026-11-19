<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

use Countable;
use Generator;
use IteratorAggregate;

/**
 * A (source, offset, length) byte range of an entry's data.
 *
 * @phpstan-type Segment array{0: resource, 1: int, 2: int}
 *
 * @implements IteratorAggregate<string, VpkEntry>
 */
final class VpkArchive implements Countable, IteratorAggregate
{
    public const int SIGNATURE = 0x55AA1234;

    /**
     * Entry metadata that follows each file name in the directory tree.
     */
    private const string METADATA_FORMAT = 'VCRC/vPreloadBytes/vArchiveIndex/VEntryOffset/VEntryLength/vTerminator';

    private const int METADATA_LENGTH = 18;

    /**
     * In-memory record: dirId, extId and preloadOffset (4 bytes each), the raw metadata, then the file name.
     */
    private const string RECORD_FORMAT = 'VdirId/VextId/VpreloadOffset/'.self::METADATA_FORMAT;

    private const int RECORD_NAME_OFFSET = 12 + self::METADATA_LENGTH;

    /**
     * Length of the "dir.vpk" suffix that is swapped for "NNN.vpk" to name a chunk file.
     */
    private const int DIR_SUFFIX_LENGTH = 7;

    private readonly BinaryStream $stream;

    private int $version;

    /**
     * The size, in bytes, of the directory tree.
     */
    private int $treeSize = 0;

    /**
     * How many bytes of file content are stored in this VPK file (0 in CSGO).
     */
    private int $fileDataSectionSize = 0;

    /**
     * The size, in bytes, of the section containing MD5 checksums for external archive content.
     */
    private int $archiveMd5SectionSize = 0;

    /**
     * The size, in bytes, of the section containing MD5 checksums for content in this file.
     */
    private int $otherMd5SectionSize = 0;

    /**
     * The size, in bytes, of the section containing the public key and signature.
     */
    private int $signatureSectionSize = 0;

    /**
     * Absolute offset in the _dir.vpk where the directory tree ends and embedded file data begins.
     */
    private int $dataOffset;

    /**
     * Directory names as stored on disk (' ' is the root).
     *
     * @var list<string>
     */
    private array $dirs = [];

    /**
     * Extensions as stored on disk (' ' means none).
     *
     * @var list<string>
     */
    private array $exts = [];

    /**
     * Binary entry records (see RECORD_FORMAT), keyed by normalized path. Stored as strings
     * instead of VpkEntry objects to save memory; createEntry() builds the object on demand.
     *
     * @var array<string, string>
     */
    private array $records = [];

    /**
     * Lazily opened chunk handles, keyed by ArchiveIndex; false marks a chunk known to be missing.
     *
     * @var array<int, resource|false>
     */
    private array $chunks = [];

    /**
     * @throws VpkException
     */
    public function __construct(
        private readonly string $vpkPath
    ) {
        $this->stream = new BinaryStream($this->vpkPath);
        $this->readHeader();
        $this->readTree();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Open the archive whose directory file (`<name>_dir.vpk`) lives at $vpkPath.
     *
     * @throws VpkException
     */
    public static function fromPath(string $vpkPath): self
    {
        return new self($vpkPath);
    }

    /**
     * "<name>_dir.vpk" → "<name>_NNN.vpk".
     *
     * @internal
     */
    public static function chunkPath(string $dirPath, int $index): string
    {
        $prefix = mb_substr(basename($dirPath), 0, -self::DIR_SUFFIX_LENGTH);

        return sprintf('%s/%s%03d.vpk', dirname($dirPath), $prefix, $index);
    }

    public function path(): string
    {
        return $this->vpkPath;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function treeSize(): int
    {
        return $this->treeSize;
    }

    public function fileDataSectionSize(): int
    {
        return $this->fileDataSectionSize;
    }

    public function archiveMd5SectionSize(): int
    {
        return $this->archiveMd5SectionSize;
    }

    public function otherMd5SectionSize(): int
    {
        return $this->otherMd5SectionSize;
    }

    public function signatureSectionSize(): int
    {
        return $this->signatureSectionSize;
    }

    /**
     * @return list<VpkEntry>
     */
    public function entries(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return array_map($this->getPath(...), array_values($this->records));
    }

    /**
     * Lookups are case-insensitive and accept either slash style, matching the Source engine.
     */
    public function has(string $path): bool
    {
        return isset($this->records[VpkEntry::normalizePath($path)]);
    }

    public function entry(string $path): ?VpkEntry
    {
        $record = $this->records[VpkEntry::normalizePath($path)] ?? null;

        return $record === null ? null : $this->createEntry($record);
    }

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * @return Generator<string, VpkEntry>
     */
    public function getIterator(): Generator
    {
        foreach ($this->records as $record) {
            $entry = $this->createEntry($record);

            yield $entry->path => $entry;
        }
    }

    /**
     * Unique directory paths in the archive ('' is the root).
     *
     * @return list<string>
     */
    public function directories(): array
    {
        return array_values(array_unique(array_map(VpkEntry::normalizePart(...), $this->dirs)));
    }

    /**
     * Unique file extensions in the archive, without the leading dot.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return array_values(array_unique(array_filter(
            $this->exts,
            static fn (string $ext): bool => $ext !== VpkEntry::BLANK,
        )));
    }

    /**
     * Entries located in $directory (and its subdirectories when $recursive is true).
     *
     * @return list<VpkEntry>
     */
    public function filesIn(string $directory, bool $recursive = false): array
    {
        return $this->filter(static fn (VpkEntry $entry): bool => $entry->isIn($directory, $recursive));
    }

    /**
     * Entries whose path matches a shell-style glob, case-insensitively (e.g. "materials/*.vmt").
     * Note that '*' also matches across '/'.
     *
     * @return list<VpkEntry>
     */
    public function find(string $pattern): array
    {
        $pattern = VpkEntry::normalizePath($pattern);

        return $this->filter(static fn (VpkEntry $entry): bool => fnmatch($pattern, VpkEntry::normalizePath($entry->path)));
    }

    /**
     * @param  callable(VpkEntry): bool  $callback
     * @return list<VpkEntry>
     */
    public function filter(callable $callback): array
    {
        $matches = [];
        foreach ($this->getIterator() as $entry) {
            if ($callback($entry)) {
                $matches[] = $entry;
            }
        }

        return $matches;
    }

    /**
     * Combined uncompressed size of every file in the archive, in bytes.
     */
    public function totalSize(): int
    {
        $total = 0;
        foreach ($this->records as $record) {
            $data = unpack(self::RECORD_FORMAT, $record);
            $total += $data['PreloadBytes'] + $data['EntryLength'];
        }

        return $total;
    }

    /**
     * Copy an entry's contents into an open, writable stream without buffering it in PHP memory.
     *
     * @param  resource  $destination
     *
     * @throws VpkException when the entry does not exist, its chunk is missing, or its data is truncated
     */
    public function writeTo(string|VpkEntry $entry, mixed $destination): void
    {
        $entry = $this->resolve($entry);

        $this->copySegments($entry, $this->segments($entry), $destination);
    }

    /**
     * Open an entry as a readable, rewound stream. Small files stay in memory; anything above
     * 2 MB is spilled to a temp file by php://temp. The caller owns (and should fclose) the stream.
     *
     * @return resource
     *
     * @throws VpkException
     */
    public function stream(string|VpkEntry $entry): mixed
    {
        $stream = Filesystem::open('php://temp', 'w+b');

        try {
            $this->writeTo($entry, $stream);
        } catch (VpkException $vpkException) {
            fclose($stream);

            throw $vpkException;
        }

        rewind($stream);

        return $stream;
    }

    /**
     * Read an entry's full contents into a string. Convenient for small files; prefer
     * stream()/writeTo()/extract*() for large ones.
     *
     * @throws VpkException
     */
    public function read(string|VpkEntry $entry): string
    {
        $stream = $this->stream($entry);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Check an entry's data against its stored CRC32. Streams the data; nothing is buffered.
     * Returns false on a CRC mismatch.
     *
     * @throws VpkException when the entry does not exist, its chunk is missing, or its data is truncated
     */
    public function verify(string|VpkEntry $entry): bool
    {
        $entry = $this->resolve($entry);
        $context = hash_init('crc32b');

        $this->readSegments(
            $entry,
            $this->segments($entry),
            static fn (mixed $source, int $length): int => hash_update_stream($context, $source, $length),
        );

        return hash_final($context) === $entry->crcHex();
    }

    /**
     * Extract a single file into $targetDir, preserving its archive path.
     * Does nothing when the file already exists, unless $overwrite is true.
     *
     * @throws VpkException
     */
    public function extractFileTo(string|VpkEntry $path, string $targetDir, bool $overwrite = false): void
    {
        $entry = $this->resolve($path);
        if (! VpkEntry::isSafePath($entry->path)) {
            throw VpkException::unsafePath($entry->path);
        }

        $this->extractEntry($entry, $targetDir.'/'.$entry->path, $overwrite);
    }

    /**
     * Extract a single file to an explicit destination file path.
     * Does nothing when the file already exists, unless $overwrite is true.
     *
     * @throws VpkException
     */
    public function extractFileAs(string|VpkEntry $path, string $destination, bool $overwrite = false): void
    {
        $this->extractEntry($this->resolve($path), $destination, $overwrite);
    }

    /**
     * Extract every entry (optionally only those accepted by $filter) into $targetDir.
     *
     * @param  string  $targetDir  directory to extract into; created on demand
     * @param  (callable(VpkEntry): bool)|null  $filter
     * @param  bool  $overwrite  re-extract files that already exist on disk
     * @return int number of files extracted (or already present)
     *
     * @throws VpkException on the first entry that fails to extract
     */
    public function extractTo(string $targetDir, ?callable $filter = null, bool $overwrite = false): int
    {
        $entries = $filter === null ? $this->getIterator() : $this->filter($filter);

        $extracted = 0;
        foreach ($entries as $entry) {
            $this->extractFileTo($entry, $targetDir, $overwrite);
            $extracted++;
        }

        return $extracted;
    }

    /**
     * Release all open chunk handles. Safe to call more than once; handles are reopened on demand.
     */
    public function close(): void
    {
        foreach ($this->chunks as $chunk) {
            if (is_resource($chunk)) {
                fclose($chunk);
            }
        }

        $this->chunks = [];
    }

    /**
     * @throws VpkException
     */
    private function readHeader(): void
    {
        $signature = $this->stream->readInt32();
        if ($signature !== self::SIGNATURE) {
            throw VpkException::invalidSignature($this->vpkPath, $signature);
        }

        $this->version = $this->stream->readInt32();

        if ($this->version >= 1) {
            $this->treeSize = $this->stream->readInt32();
        }

        if ($this->version >= 2) {
            $this->fileDataSectionSize = $this->stream->readInt32();
            $this->archiveMd5SectionSize = $this->stream->readInt32();
            $this->otherMd5SectionSize = $this->stream->readInt32();
            $this->signatureSectionSize = $this->stream->readInt32();
        }
    }

    /**
     * Walk the extension → directory → file name tree; an empty string ends each level.
     *
     * @throws VpkException
     */
    private function readTree(): void
    {
        /** @var array<string, int> $dirIds */
        $dirIds = [];

        while (($ext = $this->stream->readString()) !== '') {
            $extId = count($this->exts);
            $this->exts[] = $ext;

            while (($dir = $this->stream->readString()) !== '') {
                $dirId = $dirIds[$dir] ??= count($this->dirs);
                $this->dirs[$dirId] = $dir;

                while (($name = $this->stream->readString()) !== '') {
                    $metadata = $this->stream->read(self::METADATA_LENGTH);

                    // Preload data sits inline right after the metadata; remember where and skip it.
                    $preloadOffset = $this->stream->tell();
                    $this->stream->skip(unpack(self::METADATA_FORMAT, $metadata)['PreloadBytes']);

                    $path = VpkEntry::normalizePath(VpkEntry::joinPath($dir, $name, $ext));
                    $this->records[$path] = pack('VVV', $dirId, $extId, $preloadOffset).$metadata.$name;
                }
            }
        }

        $this->dataOffset = $this->stream->tell();
    }

    private function createEntry(string $record): VpkEntry
    {
        /** @var array{dirId: int, extId: int, preloadOffset: int, CRC: int, PreloadBytes: int, ArchiveIndex: int, EntryOffset: int, EntryLength: int, Terminator: int} $data */
        $data = unpack(self::RECORD_FORMAT, $record);

        $entry = new VpkEntry($this->dirs[$data['dirId']], $this->getName($record), $this->exts[$data['extId']]);
        $entry->preloadOffset = $data['preloadOffset'];
        $entry->CRC = $data['CRC'];
        $entry->PreloadBytes = $data['PreloadBytes'];
        $entry->ArchiveIndex = $data['ArchiveIndex'];
        $entry->EntryOffset = $data['EntryOffset'];
        $entry->EntryLength = $data['EntryLength'];
        $entry->Terminator = $data['Terminator'];

        return $entry;
    }

    private function getPath(string $record): string
    {
        /** @var array{dirId: int, extId: int} $ids */
        $ids = unpack('VdirId/VextId', $record);

        return VpkEntry::joinPath($this->dirs[$ids['dirId']], $this->getName($record), $this->exts[$ids['extId']]);
    }

    private function getName(string $record): string
    {
        // '8bit': offset in bytes.
        return mb_substr($record, self::RECORD_NAME_OFFSET, null, '8bit');
    }

    /**
     * @throws VpkException
     */
    private function resolve(string|VpkEntry $entry): VpkEntry
    {
        if ($entry instanceof VpkEntry) {
            return $entry;
        }

        return $this->entry($entry) ?? throw VpkException::entryNotFound($entry, $this->vpkPath);
    }

    /**
     * The byte ranges that make up an entry's data, in order: preload bytes from the dir file,
     * then the archived bytes from a chunk file (or from the dir file, after the tree).
     *
     * @return list<Segment>
     *
     * @throws VpkException when a required chunk file is missing
     */
    private function segments(VpkEntry $entry): array
    {
        $segments = [];

        if ($entry->PreloadBytes > 0) {
            $segments[] = [$this->stream->resource(), $entry->preloadOffset, $entry->PreloadBytes];
        }

        if ($entry->EntryLength > 0) {
            $segments[] = $entry->isStoredInDirectory()
                ? [$this->stream->resource(), $this->dataOffset + $entry->EntryOffset, $entry->EntryLength]
                : [$this->openChunk($entry), $entry->EntryOffset, $entry->EntryLength];
        }

        return $segments;
    }

    /**
     * @param  list<Segment>  $segments
     * @param  resource  $destination
     *
     * @throws VpkException
     */
    private function copySegments(VpkEntry $entry, array $segments, mixed $destination): void
    {
        $this->readSegments(
            $entry,
            $segments,
            static fn (mixed $source, int $length): int|false => stream_copy_to_stream($source, $destination, $length),
        );
    }

    /**
     * Seek to each segment and hand it to $consume, which must consume exactly $length bytes.
     *
     * @param  list<Segment>  $segments
     * @param  callable(resource, int): (int|false)  $consume  returns the number of bytes consumed
     *
     * @throws VpkException
     */
    private function readSegments(VpkEntry $entry, array $segments, callable $consume): void
    {
        foreach ($segments as [$source, $offset, $length]) {
            // Seek explicitly: stream_copy_to_stream() ignores an offset of 0.
            if (fseek($source, $offset) !== 0) {
                throw VpkException::seekFailed($offset);
            }

            $consumed = (int) $consume($source, $length);
            if ($consumed !== $length) {
                throw VpkException::truncatedData($entry->path, $offset, $length, $consumed);
            }
        }
    }

    /**
     * Chunk handles are opened lazily and cached; a missing chunk is cached as false.
     *
     * @return resource
     *
     * @throws VpkException
     */
    private function openChunk(VpkEntry $entry): mixed
    {
        $index = $entry->ArchiveIndex;
        $path = self::chunkPath($this->vpkPath, $index);

        $chunk = $this->chunks[$index] ??= is_file($path) ? Filesystem::open($path, 'rb') : false;

        return $chunk === false ? throw VpkException::chunkNotFound($entry->path, $index, $path) : $chunk;
    }

    /**
     * Stream an entry to $file via a temporary ".part" file, so an interrupted extraction
     * never leaves a truncated file that later runs would mistake for a complete one.
     *
     * @throws VpkException
     */
    private function extractEntry(VpkEntry $entry, string $file, bool $overwrite): void
    {
        if (! $overwrite && file_exists($file)) {
            return;
        }

        // Resolve the segments first, so a missing chunk fails before anything touches the disk.
        $segments = $this->segments($entry);

        Filesystem::writeFile($file, fn (mixed $handle) => $this->copySegments($entry, $segments, $handle));
    }
}
