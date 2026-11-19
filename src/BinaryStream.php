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
        $resource = @fopen($file, 'rb');
        if ($resource === false) {
            throw new VpkException(sprintf('Unable to open "%s" for reading.', $file));
        }

        $this->resource = $resource;
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
     * Read a null-terminated string, consuming the terminator. Returns an
     * empty string at end of file.
     */
    public function readString(): string
    {
        $string = stream_get_line($this->resource, 0, "\0");

        return $string === false ? '' : $string;
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

        $data = fread($this->resource, $length);
        // '8bit' makes mb_strlen count bytes, not characters.
        if ($data === false || mb_strlen($data, '8bit') !== $length) {
            throw new VpkException('Unexpected end of file.');
        }

        return $data;
    }

    public function tell(): int
    {
        return (int) ftell($this->resource);
    }

    public function seek(int $offset): void
    {
        fseek($this->resource, $offset);
    }

    public function skip(int $length): void
    {
        if ($length > 0) {
            fseek($this->resource, $length, SEEK_CUR);
        }
    }

    /**
     * @return resource
     */
    public function resource(): mixed
    {
        return $this->resource;
    }
}
