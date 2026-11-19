<?php

declare(strict_types=1);

use MoonlyDays\VPK\BinaryStream;

function writeTempBinary(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'bs_');
    file_put_contents($path, $bytes);

    return $path;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/bs_*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('reads a single byte as an unsigned integer', function (): void {
    $path = writeTempBinary("\x2A");
    $stream = new BinaryStream($path);

    expect($stream->readByte())->toBe(42);
});

it('reads little-endian uint16 values', function (): void {
    // 0x1234 little-endian → 0x34 0x12
    $path = writeTempBinary("\x34\x12");
    $stream = new BinaryStream($path);

    expect($stream->readInt16())->toBe(0x1234);
});

it('reads little-endian uint32 values', function (): void {
    // 0x78563412 little-endian → 0x12 0x34 0x56 0x78
    $path = writeTempBinary("\x12\x34\x56\x78");
    $stream = new BinaryStream($path);

    expect($stream->readInt32())->toBe(0x78563412);
});

it('reads multiple values sequentially without losing position', function (): void {
    // uint32=1, uint16=0xFFFF, then byte=0x7F
    $path = writeTempBinary("\x01\x00\x00\x00\xFF\xFF\x7F");
    $stream = new BinaryStream($path);

    expect($stream->readInt32())->toBe(1)
        ->and($stream->readInt16())->toBe(0xFFFF)
        ->and($stream->readByte())->toBe(0x7F);
});

it('reads null-terminated strings and consumes the terminator', function (): void {
    $path = writeTempBinary("hello\0world\0");
    $stream = new BinaryStream($path);

    expect($stream->readString())->toBe('hello')
        ->and($stream->readString())->toBe('world');
});

it('returns an empty string when the first byte is a null terminator', function (): void {
    $path = writeTempBinary("\0abc\0");
    $stream = new BinaryStream($path);

    expect($stream->readString())->toBe('')
        ->and($stream->readString())->toBe('abc');
});
