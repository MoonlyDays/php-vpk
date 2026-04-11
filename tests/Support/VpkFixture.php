<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds a minimal, deterministic VPK v1 archive pair on disk (`*_dir.vpk`
 * + `*_000.vpk`) for integration tests.
 *
 * The VPK v1 on-disk layout produced here:
 *
 *   Header (12 bytes):
 *     uint32 signature = 0x55AA1234
 *     uint32 version   = 1
 *     uint32 treeSize  = size of the tree section below
 *
 *   Tree (triple-nested, null-terminated strings):
 *     for each extension:
 *       for each directory:
 *         for each filename:
 *           uint32 CRC
 *           uint16 PreloadBytes
 *           uint16 ArchiveIndex
 *           uint32 EntryOffset
 *           uint32 EntryLength
 *           uint16 Terminator = 0xFFFF
 *         <null>   // end of filenames
 *       <null>     // end of directories
 *     <null>       // end of extensions
 */
final class VpkFixture
{
    /**
     * @param  array<int, array{path: string, data: string}>  $files
     * @return string absolute path to the generated `*_dir.vpk`
     */
    public static function create(string $tempDir, array $files, string $baseName = 'test'): string
    {
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        // Concatenate all file payloads into a single chunk file; remember each
        // entry's offset/length so the directory tree can point at them.
        $chunk = '';
        $entries = [];
        foreach ($files as $file) {
            $offset = mb_strlen($chunk);
            $chunk .= $file['data'];
            $entries[] = [
                'path' => $file['path'],
                'data' => $file['data'],
                'offset' => $offset,
                'length' => mb_strlen($file['data']),
            ];
        }

        // Group entries by extension → directory → [filename, meta] so we can
        // emit them in the nested order the format requires.
        $grouped = [];
        foreach ($entries as $entry) {
            [$dir, $name, $ext] = self::splitPath($entry['path']);
            $grouped[$ext][$dir][] = [
                'name' => $name,
                'entry' => $entry,
            ];
        }

        $tree = '';
        foreach ($grouped as $ext => $dirs) {
            $tree .= $ext."\0";
            foreach ($dirs as $dir => $items) {
                $tree .= $dir."\0";
                foreach ($items as $item) {
                    $tree .= $item['name']."\0";
                    $tree .= pack('V', crc32($item['entry']['data']));
                    $tree .= pack('v', 0);                         // PreloadBytes
                    $tree .= pack('v', 0);                         // ArchiveIndex → _000.vpk
                    $tree .= pack('V', $item['entry']['offset']);  // EntryOffset
                    $tree .= pack('V', $item['entry']['length']);  // EntryLength
                    $tree .= pack('v', 0xFFFF);                    // Terminator
                }
                $tree .= "\0"; // end of filenames
            }
            $tree .= "\0"; // end of directories
        }
        $tree .= "\0"; // end of extensions

        $header = pack('V', 0x55AA1234).pack('V', 1).pack('V', mb_strlen($tree));

        $dirPath = $tempDir.'/'.$baseName.'_dir.vpk';
        $chunkPath = $tempDir.'/'.$baseName.'_000.vpk';
        file_put_contents($dirPath, $header.$tree);
        file_put_contents($chunkPath, $chunk);

        return $dirPath;
    }

    public static function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                self::cleanup($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Split "a/b/foo.txt" into [dir, name, ext]. The VPK format uses a single
     * space character as the "empty" sentinel for both dir and ext, so we
     * return ' ' rather than " when either piece is missing.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function splitPath(string $path): array
    {
        $dirSep = mb_strrpos($path, '/');
        if ($dirSep === false) {
            $dir = ' ';
            $file = $path;
        } else {
            $dir = mb_substr($path, 0, $dirSep);
            $file = mb_substr($path, $dirSep + 1);
        }

        $dot = mb_strrpos($file, '.');
        if ($dot === false) {
            $name = $file;
            $ext = ' ';
        } else {
            $name = mb_substr($file, 0, $dot);
            $ext = mb_substr($file, $dot + 1);
        }

        return [$dir, $name, $ext];
    }
}
