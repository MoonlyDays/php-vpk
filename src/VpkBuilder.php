<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

use Countable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Builds a VPK (version 1) archive from files on disk and in-memory strings.
 *
 * By default all data is stored in one self-contained file. Call chunkSize() to write a
 * "<name>_dir.vpk" plus "<name>_NNN.vpk" chunks instead. File data is streamed; it is
 * never loaded into memory as a whole.
 *
 * @phpstan-type Source array{path: string, file: ?string, contents: ?string}
 * @phpstan-type Item array{source: Source, dir: string, name: string, ext: string, crc: int, size: int, index: int, offset: int}
 */
final class VpkBuilder implements Countable
{
    /**
     * Largest offset or size a VPK v1 entry can store (unsigned 32-bit).
     */
    public const int MAX_SIZE = 0xFFFFFFFF;

    private const int VERSION = 1;

    private const int TERMINATOR = 0xFFFF;

    /**
     * Metadata written after each file name: CRC, PreloadBytes, ArchiveIndex, EntryOffset, EntryLength, Terminator.
     */
    private const string METADATA_FORMAT = 'VvvVVv';

    /**
     * Sources keyed by normalized path, so adding the same path twice replaces the first one.
     *
     * @var array<string, Source>
     */
    private array $sources = [];

    private ?int $chunkSize = null;

    public static function create(): self
    {
        return new self();
    }

    /**
     * Add the file at $file under the archive path $path.
     *
     * @throws VpkException
     */
    public function addFile(string $path, string $file): self
    {
        if (! is_file($file)) {
            throw VpkException::fileNotFound($file);
        }

        return $this->add($path, $file, null);
    }

    /**
     * Add $contents under the archive path $path.
     *
     * @throws VpkException
     */
    public function addString(string $path, string $contents): self
    {
        return $this->add($path, null, $contents);
    }

    /**
     * Add every file below $dir, keeping its relative path (under $prefix, if given).
     *
     * @throws VpkException
     */
    public function addDirectory(string $dir, string $prefix = ''): self
    {
        if (! is_dir($dir)) {
            throw VpkException::directoryNotFound($dir);
        }

        $root = mb_rtrim(str_replace('\\', '/', $dir), '/').'/';
        $prefix = mb_trim(str_replace('\\', '/', $prefix), '/');

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = mb_substr(str_replace('\\', '/', $file->getPathname()), mb_strlen($root));
            $this->addFile($prefix === '' ? $relative : $prefix.'/'.$relative, $file->getPathname());
        }

        return $this;
    }

    /**
     * Split file data into "<name>_NNN.vpk" chunks of at most $bytes each. A file larger
     * than $bytes gets a chunk of its own, since a file's data cannot span chunks.
     *
     * @throws VpkException
     */
    public function chunkSize(int $bytes): self
    {
        if ($bytes < 1 || $bytes > self::MAX_SIZE) {
            throw VpkException::invalidChunkSize($bytes);
        }

        $this->chunkSize = $bytes;

        return $this;
    }

    public function has(string $path): bool
    {
        return isset($this->sources[VpkEntry::normalizePath($path)]);
    }

    public function count(): int
    {
        return count($this->sources);
    }

    /**
     * Write the archive to $path and open it. Existing files are replaced. With chunkSize(),
     * $path must end in "_dir.vpk"; the chunks are written next to it, before it.
     *
     * @throws VpkException
     */
    public function build(string $path): VpkArchive
    {
        $chunked = $this->chunkSize !== null;
        if ($chunked && ! str_ends_with(mb_strtolower($path), '_dir.vpk')) {
            throw VpkException::invalidChunkPath($path);
        }

        $tree = $this->layout();
        $items = self::flatten($tree);

        if ($chunked) {
            $chunks = [];
            foreach ($items as $item) {
                $chunks[$item['index']][] = $item;
            }

            foreach ($chunks as $index => $chunkItems) {
                $chunkPath = VpkArchive::chunkPath($path, $index);
                Filesystem::writeFile($chunkPath, fn (mixed $handle) => $this->writeData($chunkItems, $handle, $chunkPath));
            }
        }

        // The dir file is written last, so it only appears once its chunks are complete.
        Filesystem::writeFile($path, function (mixed $handle) use ($tree, $items, $chunked, $path): void {
            $treeData = self::writeTree($tree);
            $header = pack('VVV', VpkArchive::SIGNATURE, self::VERSION, mb_strlen($treeData, '8bit'));

            Filesystem::write($handle, $header.$treeData, $path);

            if (! $chunked) {
                $this->writeData($items, $handle, $path);
            }
        });

        return VpkArchive::fromPath($path);
    }

    /**
     * Sort by extension, then directory, then name, matching the tree's nesting.
     */
    private static function sortKey(string $path): string
    {
        [$dir, $name, $ext] = VpkEntry::splitPath($path);

        return $ext."\0".$dir."\0".$name;
    }

    /**
     * @param  array<array-key, array<array-key, list<Item>>>  $tree
     * @return list<Item>
     */
    private static function flatten(array $tree): array
    {
        $items = [];
        foreach ($tree as $dirs) {
            foreach ($dirs as $dirItems) {
                array_push($items, ...$dirItems);
            }
        }

        return $items;
    }

    /**
     * @param  array<array-key, array<array-key, list<Item>>>  $tree
     */
    private static function writeTree(array $tree): string
    {
        $data = '';
        foreach ($tree as $ext => $dirs) {
            $data .= $ext."\0";

            foreach ($dirs as $dir => $items) {
                $data .= $dir."\0";

                foreach ($items as $item) {
                    $data .= $item['name']."\0".pack(
                        self::METADATA_FORMAT,
                        $item['crc'],
                        0,
                        $item['index'],
                        $item['offset'],
                        $item['size'],
                        self::TERMINATOR,
                    );
                }

                $data .= "\0";
            }

            $data .= "\0";
        }

        return $data."\0";
    }

    /**
     * @throws VpkException
     */
    private function add(string $path, ?string $file, ?string $contents): self
    {
        $clean = mb_trim(str_replace('\\', '/', $path), '/');
        if (! VpkEntry::isSafePath($clean)) {
            throw VpkException::invalidPath($path);
        }

        $this->sources[VpkEntry::normalizePath($clean)] = ['path' => $clean, 'file' => $file, 'contents' => $contents];

        return $this;
    }

    /**
     * Group the sources into the ext → dir → name tree (sorted, for reproducible output)
     * and assign each one its CRC, size, archive index and offset.
     *
     * @return array<array-key, array<array-key, list<Item>>>
     *
     * @throws VpkException
     */
    private function layout(): array
    {
        $sources = $this->sources;
        uksort($sources, static fn (int|string $a, int|string $b): int => strcmp(self::sortKey((string) $a), self::sortKey((string) $b)));

        $index = $this->chunkSize === null ? VpkEntry::DIRECTORY_ARCHIVE_INDEX : 0;
        $offset = 0;
        $tree = [];

        foreach ($sources as $source) {
            [$dir, $name, $ext] = VpkEntry::splitPath($source['path']);
            [$crc, $size] = $this->hash($source);

            if ($this->chunkSize !== null && $offset > 0 && $offset + $size > $this->chunkSize) {
                $index++;
                $offset = 0;
            }

            if ($offset + $size > self::MAX_SIZE) {
                throw VpkException::sizeLimitExceeded($source['path'], $offset, $size);
            }

            $tree[$ext][$dir][] = [
                'source' => $source,
                'dir' => $dir,
                'name' => $name,
                'ext' => $ext,
                'crc' => $crc,
                'size' => $size,
                'index' => $index,
                'offset' => $offset,
            ];
            $offset += $size;
        }

        return $tree;
    }

    /**
     * Stream each item's data into $handle, which is open on $file.
     *
     * @param  list<Item>  $items
     * @param  resource  $handle
     *
     * @throws VpkException
     */
    private function writeData(array $items, mixed $handle, string $file): void
    {
        foreach ($items as $item) {
            $source = $this->open($item['source']);

            try {
                $copied = stream_copy_to_stream($source, $handle, $item['size']);
            } finally {
                fclose($source);
            }

            if ($copied === false) {
                throw VpkException::writeFailed($file, 'stream copy failed');
            }

            if ($copied !== $item['size']) {
                throw VpkException::sizeChanged($item['source']['path'], $item['size'], $copied);
            }
        }
    }

    /**
     * CRC32 and size of a source, computed by streaming it.
     *
     * @param  Source  $source
     * @return array{0: int, 1: int}
     *
     * @throws VpkException
     */
    private function hash(array $source): array
    {
        $stream = $this->open($source);
        $context = hash_init('crc32b');

        try {
            $size = hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return [(int) hexdec(hash_final($context)), $size];
    }

    /**
     * @param  Source  $source
     * @return resource
     *
     * @throws VpkException
     */
    private function open(array $source): mixed
    {
        if ($source['file'] !== null) {
            return Filesystem::open($source['file'], 'rb');
        }

        $stream = Filesystem::open('php://memory', 'w+b');
        Filesystem::write($stream, (string) $source['contents'], 'php://memory');
        rewind($stream);

        return $stream;
    }
}
