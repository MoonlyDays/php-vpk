<?php

declare(strict_types=1);

use MoonlyDays\VPK\VpkEntry;

it('assembles the full path from a directory, name, and extension', function (): void {
    $entry = new VpkEntry('materials/models', 'weapon', 'vmt');

    expect($entry->directory)->toBe('materials/models')
        ->and($entry->name)->toBe('weapon')
        ->and($entry->extension)->toBe('vmt')
        ->and($entry->path)->toBe('materials/models/weapon.vmt');
});

it('treats a single space as the empty-directory sentinel', function (): void {
    // The VPK format uses the literal ' ' character to represent "root".
    $entry = new VpkEntry(' ', 'readme', 'txt');

    expect($entry->directory)->toBe('')
        ->and($entry->path)->toBe('readme.txt');
});

it('treats a single space as the empty-extension sentinel', function (): void {
    $entry = new VpkEntry('scripts', 'Makefile', ' ');

    expect($entry->extension)->toBe('')
        ->and($entry->path)->toBe('scripts/Makefile');
});

it('handles both sentinels at once for a bare, extensionless root file', function (): void {
    $entry = new VpkEntry(' ', 'LICENSE', ' ');

    expect($entry->directory)->toBe('')
        ->and($entry->extension)->toBe('')
        ->and($entry->path)->toBe('LICENSE');
});

it('exposes the raw on-disk directory-record fields as public properties', function (): void {
    $entry = new VpkEntry('a', 'b', 'c');
    $entry->CRC = 0xDEADBEEF;
    $entry->PreloadBytes = 0;
    $entry->ArchiveIndex = 0;
    $entry->EntryOffset = 0;
    $entry->EntryLength = 0;
    $entry->Terminator = 0xFFFF;

    expect($entry->CRC)->toBe(0xDEADBEEF)
        ->and($entry->Terminator)->toBe(0xFFFF);
});

it('provides derived helpers', function (): void {
    $entry = new VpkEntry('materials/brick', 'red', 'vmt');
    $entry->CRC = 0xAB;
    $entry->PreloadBytes = 3;
    $entry->ArchiveIndex = VpkEntry::DIRECTORY_ARCHIVE_INDEX;
    $entry->EntryLength = 10;

    expect($entry->fileName())->toBe('red.vmt')
        ->and($entry->size())->toBe(13)
        ->and($entry->hasPreloadData())->toBeTrue()
        ->and($entry->isStoredInDirectory())->toBeTrue()
        ->and($entry->crcHex())->toBe('000000ab')
        ->and($entry->isIn('materials/brick'))->toBeTrue()
        ->and($entry->isIn('materials'))->toBeFalse()
        ->and($entry->isIn('Materials/', recursive: true))->toBeTrue()
        ->and($entry->isIn('', recursive: true))->toBeTrue()
        ->and(new VpkEntry(' ', 'LICENSE', ' ')->fileName())->toBe('LICENSE');
});
