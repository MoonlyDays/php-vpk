<?php

namespace MoonlyDays\VPK;

class BinaryStream
{
    private mixed $resource;

    public function __construct(string $file)
    {
        $this->resource = fopen($file, "rb");
    }

    public function __destruct()
    {
        fclose($this->resource);
    }

    public function readInt32(): int
    {
        $value = 0;
        for ($i = 0; $i < 4; $i++) {
            $value |= $this->readByte() << $i * 8;
        }

        return $value;
    }

    public function readInt16(): int
    {
        $value = 0;
        for ($i = 0; $i < 2; $i++) {
            $value |= $this->readByte() << $i * 8;
        }

        return $value;
    }

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

    public function readByte(): false|string
    {
        return ord(fgets($this->resource, 2));
    }
}
