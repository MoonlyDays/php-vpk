<?php

namespace MoonlyDays\VPK;


final class VpkArchive
{
    private BinaryStream $stream;
    private const SIGNATURE = 0x55AA1234;
    public int $Version = 0;

    /** @var int The size, in bytes, of the directory tree */
    public int $TreeSize = 0;
    /** @var int How many bytes of file content are stored in this VPK file (0 in CSGO) */
    public int $FileDataSectionSize = 0;
    /** @var int The size, in bytes, of the section containing MD5 checksums for external archive content */
    public int $ArchiveMD5SectionSize = 0;
    /** @var int The size, in bytes, of the section containing MD5 checksums for content in this file (should always be 48) */
    public int $OtherMD5SectionSize = 0;
    /** @var int The size, in bytes, of the section containing the public key and signature. This is either 0 (CSGO & The Ship) or 296 (HL2, HL2:DM, HL2:EP1, HL2:EP2, HL2:LC, TF2, DOD:S & CS:S) */
    public int $SignatureSectionSize = 0;

    /** @var VPKEntry[] $entries */
    private array $entries = [];
    /** @var array<string, VPKEntry> */
    private array $entryByPath = [];
    private array $files = [];

    public string $vpkPath;
    public string $vpkDir;
    public string $vpkName;
    /** @var (resource|false)[] $archives */
    private array $archives = [];

    /** @return resource|null */
    private function archive(int $idx)
    {
        // Archive is marked as unavailable.
        $archive = $this->archives[$idx] ?? null;
        if($archive === false)
        {
            // if archive is set to false
            // it is unavailable, don't bother opening it again.
            return null;
        }

        // If it set to something, this is our resource.
        if(!empty($archive)) {
            return $archive;
		}

        // substr the _dir.vpk part.
        $archiveName = substr($this->vpkName, 0, -7);
        $archiveName = sprintf("%s%03d.vpk", $archiveName, $idx);
        $archivePath = $this->vpkDir . "/" . $archiveName;
        if(!file_exists($archivePath))
        {
            // Archive is not present, mark as not available.
            $this->archives[$idx] = false;
            return null;
        }

        $archive = fopen($archivePath, "rb");
		$this->archives[$idx] = $archive;
        return $archive;
    }

    public function close(): void
    {
        foreach ($this->archives as $archive)
        {
            if ($archive === false)
                continue;

            if (empty($archive))
                continue;

            fclose($archive);
        }
    }

    /**
     * @throws Exception
     */
    public function __construct(string $filePath)
    {
        $this->vpkPath = $filePath;
        $this->vpkDir = dirname($filePath);
        $this->vpkName = basename($filePath);

        $this->stream = new BinaryStreamfilePath);
        $sig = $this->stream->readInt32();
		
        if($sig != self::SIGNATURE) {
            throw new VpkException("Invalid Header Signature");
		}

        $this->Version = $this->stream->readInt32();

        // only read this for VPKs of version 2.0
        if($this->Version >= 1)
        {
            $this->TreeSize = $this->stream->readInt32();
        }

        if($this->Version >= 2)
        {
            $this->FileDataSectionSize = $this->stream->readInt32();
            $this->ArchiveMD5SectionSize = $this->stream->readInt32();
            $this->OtherMD5SectionSize = $this->stream->readInt32();
            $this->SignatureSectionSize = $this->stream->readInt32();
        }

        while(true)
        {
            $fileExt = $this->stream->readString();
            if($fileExt == "") {
                break;
			}

            while(true)
            {
                $fileDir = $this->stream->readString();
                if($fileDir == "") {
                    break;
				}

                while(true)
                {
                    $fileName = $this->stream->readString();
                    if($fileName == "") {
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

    public function extract(string $path, string $dir = null): bool
    {
        $entry = $this->entryByPath[$path] ?? null;
        if(empty($entry)) {
            return false;
		}

        return $this->extractEntry($entry, $dir);
    }

    public function extractEntry(VPKEntry $entry, string $dir = null): bool
    {
        // If we don't provide directory to extract files
        // use our parent directory.
        if(empty($dir)) {
            $dir = $this->vpkDir;
		}

        $absFilePath = $dir . "/" . $entry->path;
        $absFileDir = dirname($absFilePath);

        // File is already extracted.
        if(file_exists($absFilePath)) {
            return true;
		}

        $archiveIdx = $entry->ArchiveIndex;
        $archive = $this->archive($archiveIdx);

        // No archive -- no data.
        if(empty($archive)) {
            return false;
		}

        fseek($archive, $entry->EntryOffset);
        $data = fread($archive, $entry->EntryLength);

        if(!is_dir($absFileDir)) {
            mkdir($absFileDir, 0777, true);
		}

        file_put_contents($dir . "/" . $entry->path, $data);
        return true;
    }

    public function extractFiles(string $dir = null): void
    {
        $count = count($this->entries);
        $i = 0;
        foreach ($this->entries as $entry)
        {
            echo sprintf("%s: (%.2f%%) (%d/%d) (%d) %s\n",
                $this->vpkName,
                $i / $count * 100,
                $i, $count,
                $entry->EntryLength,
                $entry->path);
            $i++;

            $this->extractEntry($entry);
        }
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getEntries(): array
    {
        return $this->entries;
    }

    public function dump(): string
    {
        $dump = "";
        foreach ($this->entries as $entry)
        {
            $hexCRC = dechex($entry->CRC);
            $dump .= sprintf("%s %s %d\n", $entry->path, $hexCRC, $entry->EntryLength);
        }
        return $dump;
    }
}
