<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

final class VpkEntry
{
    /**
     * ArchiveIndex value meaning the data follows the directory tree inside the _dir.vpk itself.
     */
    public const int DIRECTORY_ARCHIVE_INDEX = 0x7FFF;

    public string $path;

    public string $extension = '';

    public string $directory = '';

    public string $name;

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

    public function __construct(string $dir, string $name, string $ext)
    {
        $filePath = $name;
        $this->name = $name;

        if ($ext !== ' ') {
            $this->extension = $ext;
            $filePath .= '.'.$ext;
        }

        if ($dir !== ' ') {
            $this->directory = $dir;
            $filePath = $dir.'/'.$filePath;
        }

        $this->path = $filePath;
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
        $directory = mb_strtolower(mb_trim(str_replace('\\', '/', $directory), '/'));
        $own = mb_strtolower($this->directory);

        if ($own === $directory) {
            return true;
        }

        if (! $recursive) {
            return false;
        }

        return $directory === '' || str_starts_with($own, $directory.'/');
    }
}
