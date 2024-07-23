<?php

namespace MoonlyDays\VPK;

use Exception;

final class VpkArchive
{
    protected const SIGNATURE = 0x55AA1234;

    protected int $Version = 0;

    /**
     * The size, in bytes, of the directory tree
     */
    protected int $TreeSize = 0;

    /**
     * How many bytes of file content are stored in this VPK file (0 in CSGO)
     */
    protected int $FileDataSectionSize = 0;

    /**
     * The size, in bytes, of the section containing MD5 checksums for external archive content
     */
    protected int $ArchiveMD5SectionSize = 0;

    /**
     * The size, in bytes, of the section containing MD5 checksums for content in this file (should always be 48)
     */
    protected int $OtherMD5SectionSize = 0;

    /**
     * The size, in bytes, of the section containing the public key and signature. This is either 0 (CSGO & The Ship) or 296 (HL2, HL2:DM, HL2:EP1, HL2:EP2, HL2:LC, TF2, DOD:S & CS:S)
     */
    protected int $SignatureSectionSize = 0;

    protected BinaryStream $stream;

    protected string $vpkPath;

    protected string $vpkDir;

    protected string $vpkName;

    protected array $entries = [];

    protected array $entryByPath = [];

    protected array $files = [];

    protected array $archives = [];

    /**
     * @throws VpkException
     * @throws Exception
     */
    public function __construct(string $filePath)
    {
        $this->vpkPath = $filePath;
        $this->vpkDir = dirname($filePath);
        $this->vpkName = basename($filePath);

        $this->stream = BinaryStream::fromFile($filePath);
        $sig = $this->stream->readInt32();

        if ($sig != self::SIGNATURE) {
            throw new VpkException('Invalid Header Signature');
        }

        $this->Version = $this->stream->readInt32();

        // only read this for VPKs of version 2.0
        if ($this->Version >= 1) {
            $this->TreeSize = $this->stream->readInt32();
        }

        if ($this->Version >= 2) {
            $this->FileDataSectionSize = $this->stream->readInt32();
            $this->ArchiveMD5SectionSize = $this->stream->readInt32();
            $this->OtherMD5SectionSize = $this->stream->readInt32();
            $this->SignatureSectionSize = $this->stream->readInt32();
        }

        while (true) {
            $fileExt = $this->stream->readString();
            if ($fileExt == '') {
                break;
            }

            while (true) {
                $fileDir = $this->stream->readString();
                if ($fileDir == '') {
                    break;
                }

                while (true) {
                    $fileName = $this->stream->readString();
                    if ($fileName == '') {
                        break;
                    }

                    $entry = new VPKEntry($fileDir, $fileName, $fileExt);
                    $entry->CRC = $this->stream->readInt32();
                    $entry->PreloadBytes = $this->stream->readInt16();
                    $entry->ArchiveIndex = $this->stream->readInt16();
                    $entry->EntryOffset = $this->stream->readInt32();
                    $entry->EntryLength = $this->stream->readInt32();
                    $entry->Terminator = $this->stream->readInt16();

                    $this->entryByPath[$entry->path] = $entry;
                    $this->entries[] = $entry;
                    $this->files[] = $entry->path;
                }
            }
        }
    }

    /**
     * @return false|mixed|resource|null
     */
    private function openArchive(int $idx)
    {
        $archive = $this->archives[$idx] ?? null;
        if ($archive === false) {
            return null;
        }

        if (!empty($archive)) {
            return $archive;
        }

        // substr the _dir.vpk part.
        $archiveName = substr($this->vpkName, 0, -7);
        $archiveName = sprintf('%s%03d.vpk', $archiveName, $idx);
        $archivePath = $this->vpkDir.'/'.$archiveName;
        if (!file_exists($archivePath)) {
            // Archive is not present, mark as not available.
            $this->archives[$idx] = false;

            return null;
        }

        $archive = fopen($archivePath, 'rb');
        $this->archives[$idx] = $archive;

        return $archive;
    }

    protected function extractEntry(VPKEntry $entry, string $targetDir): bool
    {
        $absFilePath = $targetDir.'/'.$entry->path;
        if (file_exists($absFilePath)) {
            return true;
        }

        $archive = $this->openArchive($entry->ArchiveIndex);
        if (empty($archive)) {
            return false;
        }

        $absFileDir = dirname($absFilePath);
        fseek($archive, $entry->EntryOffset);
        $data = fread($archive, $entry->EntryLength);
        if (!is_dir($absFileDir)) {
            mkdir($absFileDir, 0777, true);
        }

        file_put_contents($absFilePath, $data);

        return true;
    }

    public function extractFileTo(string $path, string $targetDir): bool
    {
        $entry = $this->entryByPath[$path] ?? null;
        if (empty($entry)) {
            return false;
        }

        return $this->extractEntry($entry, $targetDir);
    }

    public function extractTo(string $targetDir): void
    {
        foreach ($this->entries as $entry) {
            $this->extractEntry($entry, $targetDir);
        }
    }

    public function files(): array
    {
        return $this->files;
    }

    public function close(): void
    {
        foreach ($this->archives as $archive) {
            if ($archive === false) {
                continue;
            }

            if (empty($archive)) {
                continue;
            }

            fclose($archive);
        }
    }
}
