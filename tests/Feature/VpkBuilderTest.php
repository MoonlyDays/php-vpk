<?php

declare(strict_types=1);

use MoonlyDays\VPK\VpkArchive;
use MoonlyDays\VPK\VpkBuilder;
use MoonlyDays\VPK\VpkEntry;
use MoonlyDays\VPK\VpkException;
use Tests\Support\VpkFactory;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/php-vpk-test-'.bin2hex(random_bytes(6));
    mkdir($this->tempDir, 0777, true);
});

afterEach(function (): void {
    VpkFactory::cleanup($this->tempDir);
});

/**
 * @return array<string, string>
 */
function sampleFiles(): array
{
    return [
        'materials/wood.vmt' => 'WOOD',
        'materials/brick/red.vmt' => str_repeat('R', 1000),
        'scripts/game.txt' => 'GAME',
        'LICENSE' => 'MIT',
        '.gitignore' => 'vendor',
        'empty.txt' => '',
    ];
}

function assertArchiveContains(VpkArchive $archive, array $files): void
{
    expect($archive->files())->toEqualCanonicalizing(array_keys($files));

    foreach ($files as $path => $data) {
        expect($archive->read($path))->toBe($data)
            ->and($archive->verify($path))->toBeTrue();
    }
}

it('builds a single-file archive from strings', function (): void {
    $builder = VpkBuilder::create();
    foreach (sampleFiles() as $path => $data) {
        $builder->addString($path, $data);
    }

    $archive = $builder->build($this->tempDir.'/single.vpk');

    expect($archive->version())->toBe(1)
        ->and($archive->entry('LICENSE')->isStoredInDirectory())->toBeTrue()
        ->and(glob($this->tempDir.'/*'))->toHaveCount(1);
    assertArchiveContains($archive, sampleFiles());
});

it('builds a chunked archive and starts a new chunk when one is full', function (): void {
    $archive = VpkBuilder::create()
        ->addString('a.bin', str_repeat('a', 60))
        ->addString('b.bin', str_repeat('b', 60))
        ->addString('c.bin', str_repeat('c', 10))
        ->chunkSize(100)
        ->build($this->tempDir.'/pak01_dir.vpk');

    expect($archive->entry('a.bin')->ArchiveIndex)->toBe(0)
        ->and($archive->entry('b.bin')->ArchiveIndex)->toBe(1)
        ->and($archive->entry('c.bin')->ArchiveIndex)->toBe(1)
        ->and(filesize($this->tempDir.'/pak01_000.vpk'))->toBe(60)
        ->and(filesize($this->tempDir.'/pak01_001.vpk'))->toBe(70);
    assertArchiveContains($archive, [
        'a.bin' => str_repeat('a', 60),
        'b.bin' => str_repeat('b', 60),
        'c.bin' => str_repeat('c', 10),
    ]);
});

it('gives a file larger than the chunk size a chunk of its own', function (): void {
    $archive = VpkBuilder::create()
        ->addString('big.bin', str_repeat('x', 500))
        ->addString('small.bin', 'y')
        ->chunkSize(100)
        ->build($this->tempDir.'/pak01_dir.vpk');

    expect($archive->read('big.bin'))->toBe(str_repeat('x', 500))
        ->and($archive->entry('small.bin')->ArchiveIndex)->not->toBe($archive->entry('big.bin')->ArchiveIndex);
});

it('adds a directory recursively, with an optional prefix', function (): void {
    $source = $this->tempDir.'/src';
    foreach (sampleFiles() as $path => $data) {
        is_dir(dirname($source.'/'.$path)) || mkdir(dirname($source.'/'.$path), 0777, true);
        file_put_contents($source.'/'.$path, $data);
    }

    $archive = VpkBuilder::create()
        ->addDirectory($source, 'custom')
        ->build($this->tempDir.'/out.vpk');

    $expected = [];
    foreach (sampleFiles() as $path => $data) {
        $expected['custom/'.$path] = $data;
    }

    assertArchiveContains($archive, $expected);
});

it('round-trips an archive through extraction and rebuilding', function (): void {
    $original = VpkArchive::fromPath(VpkFactory::create($this->tempDir.'/in', [
        ['path' => 'a/one.txt', 'data' => 'ONE', 'preload' => 2],
        ['path' => 'b/two.txt', 'data' => 'TWO', 'inline' => true],
    ]));
    $original->extractTo($this->tempDir.'/extracted');

    $rebuilt = VpkBuilder::create()
        ->addDirectory($this->tempDir.'/extracted')
        ->build($this->tempDir.'/rebuilt.vpk');

    assertArchiveContains($rebuilt, ['a/one.txt' => 'ONE', 'b/two.txt' => 'TWO']);
});

it('normalizes slashes and replaces a path added twice', function (): void {
    $builder = VpkBuilder::create()
        ->addString('\\Scripts\\game.txt', 'old')
        ->addString('scripts/GAME.txt', 'new');

    expect($builder)->toHaveCount(1)
        ->and($builder->has('SCRIPTS/game.txt'))->toBeTrue()
        ->and($builder->build($this->tempDir.'/out.vpk')->read('scripts/game.txt'))->toBe('new');
});

it('produces identical output regardless of the order files are added in', function (): void {
    $forward = VpkBuilder::create();
    $backward = VpkBuilder::create();
    foreach (sampleFiles() as $path => $data) {
        $forward->addString($path, $data);
    }

    foreach (array_reverse(sampleFiles(), true) as $path => $data) {
        $backward->addString($path, $data);
    }

    $forward->build($this->tempDir.'/a.vpk');
    $backward->build($this->tempDir.'/b.vpk');

    expect(file_get_contents($this->tempDir.'/a.vpk'))->toBe(file_get_contents($this->tempDir.'/b.vpk'));
});

it('rejects invalid archive paths', function (string $path): void {
    expect(fn (): VpkBuilder => VpkBuilder::create()->addString($path, 'x'))
        ->toThrow(VpkException::class, 'Invalid archive path');
})->with(['', '../evil.txt', 'a/../b.txt', 'a//b.txt', './a.txt', 'C:/a.txt', "a\0b.txt"]);

it('rejects missing sources and bad chunk settings', function (): void {
    expect(fn (): VpkBuilder => VpkBuilder::create()->addFile('a.txt', $this->tempDir.'/missing.txt'))
        ->toThrow(VpkException::class, 'does not exist')
        ->and(fn (): VpkBuilder => VpkBuilder::create()->addDirectory($this->tempDir.'/missing'))
        ->toThrow(VpkException::class, 'Directory')
        ->and(fn (): VpkBuilder => VpkBuilder::create()->chunkSize(0))
        ->toThrow(VpkException::class, 'Invalid chunk size')
        ->and(fn (): VpkArchive => VpkBuilder::create()->addString('a.txt', 'x')->chunkSize(100)->build($this->tempDir.'/pak01.vpk'))
        ->toThrow(VpkException::class, '_dir.vpk');
});

it('splits paths into on-disk directory, name and extension', function (string $path, array $parts): void {
    expect(VpkEntry::splitPath($path))->toBe($parts)
        ->and(VpkEntry::joinPath(...$parts))->toBe($path);
})->with([
    ['a/b/c.txt', ['a/b', 'c', 'txt']],
    ['root.txt', [' ', 'root', 'txt']],
    ['LICENSE', [' ', 'LICENSE', ' ']],
    ['.gitignore', [' ', '.gitignore', ' ']],
    ['dir/archive.tar.gz', ['dir', 'archive.tar', 'gz']],
    ['dir/trailing.', ['dir', 'trailing.', ' ']],
]);
