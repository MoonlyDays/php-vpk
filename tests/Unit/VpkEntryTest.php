<?php

declare(strict_types=1);

use MoonlyDays\VPK\VpkEntry;

it('assembles the full path from a directory, name, and extension', function (): void {
    $entry = new VpkEntry('materials/models', 'weapon', 'vmt');

    expect($entry->directory)->toBe('materials/models');
    expect($entry->name)->toBe('weapon');
    expect($entry->extension)->toBe('vmt');
    expect($entry->path)->toBe('materials/models/weapon.vmt');
});

it('treats a single space as the empty-directory sentinel', function (): void {
    // The VPK format uses the literal ' ' character to represent "root".
    $entry = new VpkEntry(' ', 'readme', 'txt');

    expect($entry->directory)->toBe('');
    expect($entry->path)->toBe('readme.txt');
});

it('treats a single space as the empty-extension sentinel', function (): void {
    $entry = new VpkEntry('scripts', 'Makefile', ' ');

    expect($entry->extension)->toBe('');
    expect($entry->path)->toBe('scripts/Makefile');
});

it('handles both sentinels at once for a bare, extensionless root file', function (): void {
    $entry = new VpkEntry(' ', 'LICENSE', ' ');

    expect($entry->directory)->toBe('');
    expect($entry->extension)->toBe('');
    expect($entry->path)->toBe('LICENSE');
});

it('exposes the raw on-disk directory-record fields as public properties', function (): void {
    $entry = new VpkEntry('a', 'b', 'c');
    $entry->CRC = 0xDEADBEEF;
    $entry->PreloadBytes = 0;
    $entry->ArchiveIndex = 0;
    $entry->EntryOffset = 0;
    $entry->EntryLength = 0;
    $entry->Terminator = 0xFFFF;

    expect($entry->CRC)->toBe(0xDEADBEEF);
    expect($entry->Terminator)->toBe(0xFFFF);
});
