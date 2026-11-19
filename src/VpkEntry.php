<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

final class VpkEntry
{
    /**
     * ArchiveIndex value meaning the data follows the directory tree inside the _dir.vpk itself.
     */
    public const int DIRECTORY_ARCHIVE_INDEX = 0x7FFF;

    /**
     * On-disk stand-in for "root directory" or "no extension".
     */
    public const string BLANK = ' ';

    public string $path;

    public string $extension;

    public string $directory;

    /**
     * @var int A 32bit CRC of the file's data.
     */
    public int $CRC;

    /**
     * @var int The number of bytes contained in the index file.
     */
    public int $PreloadBytes;

    /**
     * @var int A zero based index of the archive this file's data is contained in. If 0x7fff, the data follows the directory.
     */
    public int $ArchiveIndex;

    /**
     * @var int If ArchiveIndex is 0x7fff, the offset of the file data relative to the end of the directory (see the header for more details). Otherwise, the offset of the data from the start of the specified archive.
     */
    public int $EntryOffset;

    /**
     * If zero, the entire file is stored in the preload data. Otherwise, the number of bytes stored starting at EntryOffset.
     */
    public int $EntryLength;

    public int $Terminator;

    /**
     * Absolute offset of this entry's preload bytes within the _dir.vpk file.
     */
    public int $preloadOffset = 0;

    public function __construct(string $dir, public string $name, string $ext)
    {
        $this->extension = self::normalizePart($ext);
        $this->directory = self::normalizePart($dir);
        $this->path = self::joinPath($dir, $this->name, $ext);
    }

    /**
     * Build "dir/name.ext" from on-disk values (BLANK means root directory / no extension).
     */
    public static function joinPath(string $dir, string $name, string $ext): string
    {
        $path = $ext === self::BLANK ? $name : $name.'.'.$ext;

        return $dir === self::BLANK ? $path : $dir.'/'.$path;
    }

    /**
     * Split "dir/name.ext" into on-disk [dir, name, ext] values; the inverse of joinPath().
     * A leading or trailing dot (".gitignore", "file.") belongs to the name, not the extension.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function splitPath(string $path): array
    {
        $slash = mb_strrpos($path, '/');
        $dir = $slash === false ? self::BLANK : mb_substr($path, 0, $slash);
        $file = $slash === false ? $path : mb_substr($path, $slash + 1);

        $dot = mb_strrpos($file, '.');
        if ($dot === false || $dot === 0 || $dot === mb_strlen($file) - 1) {
            return [$dir, $file, self::BLANK];
        }

        return [$dir, mb_substr($file, 0, $dot), mb_substr($file, $dot + 1)];
    }

    /**
     * Whether $path is a plain relative path that cannot escape a target directory.
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, ':') || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }

        return array_all(
            explode('/', $path),
            static fn (string $part): bool => ! in_array($part, ['', '.', '..'], true),
        );
    }

    /**
     * Map the on-disk BLANK marker to an empty string.
     */
    public static function normalizePart(string $value): string
    {
        return $value === self::BLANK ? '' : $value;
    }

    /**
     * Canonical form for comparing archive paths: lowercase, forward slashes, no outer slashes.
     * Matches the Source engine, which treats paths case-insensitively.
     */
    public static function normalizePath(string $path): string
    {
        return mb_strtolower(mb_trim(str_replace('\\', '/', $path), '/'));
    }

    /**
     * The file name including its extension, e.g. "wood.vmt".
     */
    public function fileName(): string
    {
        return $this->extension === '' ? $this->name : $this->name.'.'.$this->extension;
    }

    /**
     * Total size of the file in bytes (preload data + archived data).
     */
    public function size(): int
    {
        return $this->PreloadBytes + $this->EntryLength;
    }

    public function hasPreloadData(): bool
    {
        return $this->PreloadBytes > 0;
    }

    /**
     * Whether the entry's archived data lives in the _dir.vpk itself rather than a numbered chunk.
     */
    public function isStoredInDirectory(): bool
    {
        return $this->ArchiveIndex === self::DIRECTORY_ARCHIVE_INDEX;
    }

    /**
     * The CRC formatted as an 8-character lowercase hex string (same format as hash('crc32b', ...)).
     */
    public function crcHex(): string
    {
        return sprintf('%08x', $this->CRC);
    }

    /**
     * Whether the entry lives in $directory (or below it, when $recursive is true).
     */
    public function isIn(string $directory, bool $recursive = false): bool
    {
        $directory = self::normalizePath($directory);
        $own = self::normalizePath($this->directory);

        if ($own === $directory) {
            return true;
        }

        if (! $recursive) {
            return false;
        }

        return $directory === '' || str_starts_with($own, $directory.'/');
    }
}
