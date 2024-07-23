<?php

namespace MoonlyDays\VPK;

class BinaryStream
{
    private int $ptr;
    private array $data;

    private function __construct(array $bytes)
    {
        $this->data = $bytes;
        $this->ptr = 0;
    }

    public static function fromFile(string $file): BinaryStream
    {
        $filesize = filesize($file);
        $fp = fopen($file, 'rb');
        $binary = fread($fp, $filesize);
        fclose($fp);

        $unpacked = unpack(sprintf('C%d', $filesize), $binary);
        $unpacked = array_values($unpacked);

        return new BinaryStream($unpacked);
    }

    /**
     * @throws VpkException
     */
    public function readInt32(): int
    {
        $value = 0;
        for ($i = 0; $i < 4; $i++) {
            $value |= $this->readByte() << $i * 8;
        }

        return $value;
    }

    /**
     * @throws VpkException
     */
    public function readInt16(): int
    {
        $value = 0;
        for ($i = 0; $i < 2; $i++) {
            $value |= $this->readByte() << $i * 8;
        }

        return $value;
    }

    /**
     * @throws VpkException
     */
    public function readString(): string
    {
        $chars = [];
        do {
            $char = $this->readByte();
            if ($char == 0) {
                break;
            }

            $chars[] = $char;
        } while (true);

        return implode(array_map("chr", $chars));
    }

    /**
     * @throws VpkException
     */
    public function readByte()
    {
        if ($this->ptr >= count($this->data)) {
            throw new VpkException("Buffer Overflow.");
        }

        return $this->data[$this->ptr++];
    }
}
