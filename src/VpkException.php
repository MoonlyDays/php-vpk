<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

use Exception;
use Throwable;

/**
 * Every failure in the library is raised through one of these named constructors,
 * so messages stay consistent and always name the file, entry or offset involved.
 */
class VpkException extends Exception
{
    // Opening files

    public static function fileNotFound(string $file): self
    {
        return new self(sprintf('File "%s" does not exist.', $file));
    }

    public static function openFailed(string $file, string $reason): self
    {
        return new self(sprintf('Unable to open "%s": %s.', $file, $reason));
    }

    public static function invalidSignature(string $file, int $signature): self
    {
        return new self(sprintf(
            'Invalid VPK signature in "%s": expected 0x%08X, got 0x%08X.',
            $file,
            VpkArchive::SIGNATURE,
            $signature,
        ));
    }

    // Reading binary data

    public static function unexpectedEof(int $offset, int $expected, int $actual): self
    {
        return new self(sprintf('Unexpected end of file at offset %d: expected %d bytes, got %d.', $offset, $expected, $actual));
    }

    public static function unterminatedString(int $offset): self
    {
        return new self(sprintf('Unexpected end of file at offset %d while reading a string.', $offset));
    }

    public static function seekFailed(int $offset): self
    {
        return new self(sprintf('Unable to seek to offset %d.', $offset));
    }

    public static function tellFailed(): self
    {
        return new self('Unable to determine the current stream position.');
    }

    // Reading entries

    public static function entryNotFound(string $path, string $archive): self
    {
        return new self(sprintf('Entry "%s" does not exist in "%s".', $path, $archive));
    }

    public static function chunkNotFound(string $path, int $index, string $chunkPath): self
    {
        return new self(sprintf('Cannot read "%s": archive chunk %03d ("%s") does not exist.', $path, $index, $chunkPath));
    }

    public static function truncatedData(string $path, int $offset, int $expected, int $actual): self
    {
        return new self(sprintf('Data for "%s" is truncated: expected %d bytes at offset %d, got %d.', $path, $expected, $offset, $actual));
    }

    // Extracting entries

    public static function unsafePath(string $path): self
    {
        return new self(sprintf('Refusing to extract "%s": the path would escape the target directory.', $path));
    }

    public static function mkdirFailed(string $dir, string $reason): self
    {
        return new self(sprintf('Unable to create directory "%s": %s.', $dir, $reason));
    }

    public static function renameFailed(string $from, string $to, string $reason): self
    {
        return new self(sprintf('Unable to move "%s" to "%s": %s.', $from, $to, $reason));
    }

    public static function flysystemFailed(string $path, Throwable $previous): self
    {
        return new self(sprintf('Unable to extract "%s" to the filesystem: %s', $path, $previous->getMessage()), 0, $previous);
    }
}
