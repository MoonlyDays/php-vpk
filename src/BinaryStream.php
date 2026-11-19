<?php

declare(strict_types=1);

namespace MoonlyDays\VPK;

readonly class BinaryStream
{
    /**
     * @var resource
     */
    private mixed $resource;

    /**
     * @throws VpkException
     */
    public function __construct(string $file)
    {
        if (! is_file($file)) {
            throw VpkException::fileNotFound($file);
        }

        $this->resource = Filesystem::open($file, 'rb');
    }

    public function __destruct()
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    /**
     * @throws VpkException
     */
    public function readInt32(): int
    {
        return unpack('V', $this->read(4))[1];
    }

    /**
     * @throws VpkException
     */
    public function readInt16(): int
    {
        return unpack('v', $this->read(2))[1];
    }

    /**
     * @throws VpkException
     */
    public function readByte(): int
    {
        return ord($this->read(1)[0]);
    }

    /**
     * Read a null-terminated string, consuming the terminator.
     *
     * @throws VpkException when the end of file is reached before the terminator
     */
    public function readString(): string
    {
        $offset = $this->tell();
        $string = stream_get_line($this->resource, 0, "\0");

        // A consumed terminator puts the position one byte past the string; at EOF it doesn't.
        if ($string === false || $this->tell() !== $offset + self::byteLength($string) + 1) {
            throw VpkException::unterminatedString($offset);
        }

        return $string;
    }

    /**
     * Read exactly $length raw bytes.
     *
     * @throws VpkException
     */
    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $offset = $this->tell();
        $data = (string) fread($this->resource, $length);
        if (self::byteLength($data) !== $length) {
            throw VpkException::unexpectedEof($offset, $length, self::byteLength($data));
        }

        return $data;
    }

    /**
     * @throws VpkException
     */
    public function tell(): int
    {
        $offset = ftell($this->resource);

        return $offset === false ? throw VpkException::tellFailed() : $offset;
    }

    /**
     * @throws VpkException
     */
    public function seek(int $offset): void
    {
        if (fseek($this->resource, $offset) !== 0) {
            throw VpkException::seekFailed($offset);
        }
    }

    /**
     * @throws VpkException
     */
    public function skip(int $length): void
    {
        if ($length > 0) {
            $this->seek($this->tell() + $length);
        }
    }

    /**
     * @return resource
     */
    public function resource(): mixed
    {
        return $this->resource;
    }

    private static function byteLength(string $data): int
    {
        // '8bit' makes mb_strlen count bytes, not characters.
        return mb_strlen($data, '8bit');
    }
}
