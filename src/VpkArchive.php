<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

use Countable;
use Generator;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<string, VpkEntry>
 */
final class VpkArchive implements Countable, IteratorAggregate
{
    public const int SIGNATURE = 0x55AA1234;

    /**
     * Record layout: dirId, extId, preloadOffset, the 18-byte entry metadata, then the file name.
     */
    private const string RECORD_FORMAT = 'VdirId/VextId/VpreloadOffset/VCRC/vPreloadBytes/vArchiveIndex/VEntryOffset/VEntryLength/vTerminator';

    private const int RECORD_NAME_OFFSET = 30;

    private readonly BinaryStream $stream;

    private readonly string $vpkDir;

    private readonly string $vpkName;

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
     * Lazily opened chunk handles; false marks a chunk known to be missing.
     *
     * @var array<int, resource|false>
     */
    private array $archives = [];

    /**
     * @throws VpkException
     */
    public function __construct(
        private readonly string $vpkPath
    ) {
        $this->vpkDir = dirname($this->vpkPath);
        $this->vpkName = basename($this->vpkPath);

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
        return isset($this->records[self::normalizePath($path)]);
    }

    public function entry(string $path): ?VpkEntry
    {
        $record = $this->records[self::normalizePath($path)] ?? null;

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
        return array_values(array_unique(array_map(
            static fn (string $directory): string => $directory === ' ' ? '' : $directory,
            $this->dirs,
        )));
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
            static fn (string $ext): bool => $ext !== ' ' && $ext !== '',
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
        $pattern = self::normalizePath($pattern);

        return $this->filter(static fn (VpkEntry $entry): bool => fnmatch($pattern, mb_strtolower($entry->path)));
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
            // PreloadBytes (v) at byte 16, EntryLength (V) at byte 24.
            $total += unpack('v', $record, 16)[1] + unpack('V', $record, 24)[1];
        }

        return $total;
    }

    /**
     * Copy an entry's contents into an open, writable stream without buffering it in PHP memory.
     *
     * @param  resource  $destination
     */
    public function writeTo(string|VpkEntry $entry, mixed $destination): bool
    {
        $entry = $this->resolve($entry);
        if (! $entry instanceof VpkEntry) {
            return false;
        }

        $segments = $this->segments($entry);
        if ($segments === null) {
            return false;
        }

        return $this->copySegments($segments, $destination);
    }

    /**
     * Open an entry as a readable, rewound stream. Small files stay in memory; anything above
     * 2 MB is spilled to a temp file by php://temp. The caller owns (and should fclose) the stream.
     *
     * @return resource|null
     */
    public function stream(string|VpkEntry $entry): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return null;
        }

        if (! $this->writeTo($entry, $stream)) {
            fclose($stream);

            return null;
        }

        rewind($stream);

        return $stream;
    }

    /**
     * Read an entry's full contents into a string. Convenient for small files; prefer
     * stream()/writeTo()/extract*() for large ones.
     */
    public function read(string|VpkEntry $entry): ?string
    {
        $stream = $this->stream($entry);
        if ($stream === null) {
            return null;
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? null : $contents;
    }

    /**
     * Check an entry's data against its stored CRC32. Streams the data; nothing is buffered.
     */
    public function verify(string|VpkEntry $entry): bool
    {
        $entry = $this->resolve($entry);
        if (! $entry instanceof VpkEntry) {
            return false;
        }

        $segments = $this->segments($entry);
        if ($segments === null) {
            return false;
        }

        $context = hash_init('crc32b');
        foreach ($segments as [$source, $offset, $length]) {
            fseek($source, $offset);
            if (hash_update_stream($context, $source, $length) !== $length) {
                return false;
            }
        }

        return hash_final($context) === $entry->crcHex();
    }

    /**
     * Extract a single file into $targetDir, preserving its archive path.
     */
    public function extractFileTo(string|VpkEntry $path, string $targetDir, bool $overwrite = false): bool
    {
        $entry = $this->resolve($path);
        if (! $entry instanceof VpkEntry || ! self::isSafePath($entry->path)) {
            return false;
        }

        return $this->extractEntry($entry, $targetDir.'/'.$entry->path, $overwrite);
    }

    /**
     * Extract a single file to an explicit destination file path.
     */
    public function extractFileAs(string|VpkEntry $path, string $destination, bool $overwrite = false): bool
    {
        $entry = $this->resolve($path);
        if (! $entry instanceof VpkEntry) {
            return false;
        }

        return $this->extractEntry($entry, $destination, $overwrite);
    }

    /**
     * Extract every entry (optionally only those accepted by $filter) into $targetDir.
     *
     * @param  string  $targetDir  directory to extract into; created on demand
     * @param  (callable(VpkEntry): bool)|null  $filter
     * @param  bool  $overwrite  re-extract files that already exist on disk
     * @return int number of files successfully extracted (or already present)
     */
    public function extractTo(string $targetDir, ?callable $filter = null, bool $overwrite = false): int
    {
        $extracted = 0;
        foreach ($this->getIterator() as $entry) {
            if ($filter !== null && ! $filter($entry)) {
                continue;
            }

            if ($this->extractFileTo($entry, $targetDir, $overwrite)) {
                $extracted++;
            }
        }

        return $extracted;
    }

    /**
     * Release all open chunk handles. Safe to call more than once; handles are reopened on demand.
     */
    public function close(): void
    {
        foreach ($this->archives as $archive) {
            if (is_resource($archive)) {
                fclose($archive);
            }
        }

        $this->archives = [];
    }

    private static function normalizePath(string $path): string
    {
        return mb_strtolower(mb_ltrim(str_replace('\\', '/', $path), '/'));
    }

    /**
     * Reject paths that could escape the extraction directory.
     */
    private static function isSafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, '\\')) {
            return false;
        }

        return ! in_array('..', explode('/', $path), true);
    }

    /**
     * @throws VpkException
     */
    private function readHeader(): void
    {
        if ($this->stream->readInt32() !== self::SIGNATURE) {
            throw new VpkException('Invalid Header Signature');
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
     * @throws VpkException
     */
    private function readTree(): void
    {
        /** @var array<string, int> $dirIds */
        $dirIds = [];

        while (($fileExt = $this->stream->readString()) !== '') {
            $extId = count($this->exts);
            $this->exts[] = $fileExt;

            while (($fileDir = $this->stream->readString()) !== '') {
                if (! isset($dirIds[$fileDir])) {
                    $dirIds[$fileDir] = count($this->dirs);
                    $this->dirs[] = $fileDir;
                }

                $dirId = $dirIds[$fileDir];

                while (($fileName = $this->stream->readString()) !== '') {
                    // CRC (V), PreloadBytes (v), ArchiveIndex (v), EntryOffset (V), EntryLength (V), Terminator (v).
                    $metadata = $this->stream->read(18);

                    // Preload data sits inline right after the record; remember where and skip it.
                    $preloadOffset = $this->stream->tell();
                    $this->stream->skip(unpack('v', $metadata, 4)[1]);

                    $path = VpkEntry::joinPath($fileDir, $fileName, $fileExt);
                    $this->records[self::normalizePath($path)] = pack('VVV', $dirId, $extId, $preloadOffset).$metadata.$fileName;
                }
            }
        }

        $this->dataOffset = $this->stream->tell();
    }

    private function createEntry(string $record): VpkEntry
    {
        /** @var array{dirId: int, extId: int, preloadOffset: int, CRC: int, PreloadBytes: int, ArchiveIndex: int, EntryOffset: int, EntryLength: int, Terminator: int} $data */
        $data = unpack(self::RECORD_FORMAT, $record);

        $entry = new VpkEntry(
            $this->dirs[$data['dirId']],
            $this->getName($record),
            $this->exts[$data['extId']],
        );
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
        return VpkEntry::joinPath(
            $this->dirs[unpack('V', $record)[1]],
            $this->getName($record),
            $this->exts[unpack('V', $record, 4)[1]],
        );
    }

    private function getName(string $record): string
    {
        // '8bit': offset in bytes.
        return mb_substr($record, self::RECORD_NAME_OFFSET, null, '8bit');
    }

    private function resolve(string|VpkEntry $entry): ?VpkEntry
    {
        return $entry instanceof VpkEntry ? $entry : $this->entry($entry);
    }

    /**
     * The (source, offset, length) byte ranges that make up an entry's data, in order.
     * Returns null when a required chunk file is missing.
     *
     * @return list<array{0: resource, 1: int, 2: int}>|null
     */
    private function segments(VpkEntry $entry): ?array
    {
        $segments = [];

        if ($entry->PreloadBytes > 0) {
            $segments[] = [$this->stream->resource(), $entry->preloadOffset, $entry->PreloadBytes];
        }

        if ($entry->EntryLength > 0) {
            if ($entry->isStoredInDirectory()) {
                $segments[] = [$this->stream->resource(), $this->dataOffset + $entry->EntryOffset, $entry->EntryLength];
            } else {
                $archive = $this->openArchive($entry->ArchiveIndex);
                if ($archive === null) {
                    return null;
                }

                $segments[] = [$archive, $entry->EntryOffset, $entry->EntryLength];
            }
        }

        return $segments;
    }

    /**
     * @param  list<array{0: resource, 1: int, 2: int}>  $segments
     * @param  resource  $destination
     */
    private function copySegments(array $segments, mixed $destination): bool
    {
        // Seek explicitly: stream_copy_to_stream() ignores an offset of 0.
        return array_all(
            $segments,
            static fn (array $segment): bool => fseek($segment[0], $segment[1]) === 0
                && stream_copy_to_stream($segment[0], $destination, $segment[2]) === $segment[2],
        );
    }

    /**
     * @return resource|null
     */
    private function openArchive(int $idx): mixed
    {
        $archive = $this->archives[$idx] ?? null;
        if ($archive === false) {
            return null;
        }

        if (is_resource($archive)) {
            return $archive;
        }

        // Strip the "dir.vpk" suffix, keeping the trailing underscore.
        $archiveName = sprintf('%s%03d.vpk', mb_substr($this->vpkName, 0, -7), $idx);
        $archivePath = $this->vpkDir.'/'.$archiveName;

        $archive = is_file($archivePath) ? @fopen($archivePath, 'rb') : false;
        $this->archives[$idx] = $archive;

        return $archive === false ? null : $archive;
    }

    /**
     * Stream an entry to $file via a temporary ".part" file, so an interrupted extraction
     * never leaves a truncated file that later runs would mistake for a complete one.
     */
    private function extractEntry(VpkEntry $entry, string $file, bool $overwrite): bool
    {
        if (! $overwrite && file_exists($file)) {
            return true;
        }

        $segments = $this->segments($entry);
        if ($segments === null) {
            return false;
        }

        $dir = dirname($file);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return false;
        }

        $tempFile = $file.'.part';
        $handle = @fopen($tempFile, 'wb');
        if ($handle === false) {
            return false;
        }

        $copied = $this->copySegments($segments, $handle);
        fclose($handle);

        if (! $copied || ! rename($tempFile, $file)) {
            @unlink($tempFile);

            return false;
        }

        return true;
    }
}
