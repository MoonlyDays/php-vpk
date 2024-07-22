<?php

namespace MoonlyDays\VPK;

final class VpkEntry
{
    public string $path;
    public string $extension = "";
    public string $directory = "";
    public string $name;

    public function __construct(string $dir, string $name, string $ext)
    {
        $filePath = $name;
        $this->name = $name;

        if($ext != " ")
        {
            $this->extension = $ext;
            $filePath .= "." . $ext;
        }

        if($dir != " ")
        {
            $this->directory = $dir;
            $filePath = $dir . "/" . $filePath;
        }

        $this->path = $filePath;
    }

    /** @var int A 32bit CRC of the file's data. */
    public int $CRC;
    /** @var int The number of bytes contained in the index file. */
    public int $PreloadBytes;

    /** @var int A zero based index of the archive this file's data is contained in. If 0x7fff, the data follows the directory. */
    public int $ArchiveIndex;
    /** @var int If ArchiveIndex is 0x7fff, the offset of the file data relative to the end of the directory (see the header for more details). Otherwise, the offset of the data from the start of the specified archive. */
    public int $EntryOffset;

    /** If zero, the entire file is stored in the preload data. Otherwise, the number of bytes stored starting at EntryOffset. */
    public int $EntryLength;
    public int $Terminator;

    const TERMINATOR = 0xFFFF;
}
