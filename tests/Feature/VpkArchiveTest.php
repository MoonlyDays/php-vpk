<?php

declare(strict_types=1);

use MoonlyDays\VPK\VpkArchive;
use MoonlyDays\VPK\VpkException;
use Tests\Support\VpkFixture;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/php-vpk-test-'.bin2hex(random_bytes(6));
    mkdir($this->tempDir, 0777, true);
});

afterEach(function (): void {
    VpkFixture::cleanup($this->tempDir);
});

it('rejects a file whose header signature is not 0x55AA1234', function (): void {
    $path = $this->tempDir.'/bogus_dir.vpk';
    file_put_contents($path, pack('V', 0xDEADBEEF).pack('V', 1).pack('V', 0)."\0\0\0");

    expect(fn () => new VpkArchive($path))->toThrow(VpkException::class);
});

it('returns false from extractFileTo for a path that is not in the archive', function (): void {
    $dirPath = VpkFixture::create($this->tempDir, [
        ['path' => 'present.txt', 'data' => 'here'],
    ]);
    $archive = new VpkArchive($dirPath);

    expect($archive->extractFileTo('definitely/not/in/the/archive.txt', $this->tempDir))->toBeFalse();

    $archive->close();
});

it('round-trips a synthetic archive: parse, enumerate, and extract file contents', function (): void {
    $files = [
        ['path' => 'hello.txt', 'data' => 'Hello, world!'],
        ['path' => 'docs/readme.md', 'data' => "# VPK\ncontents"],
        ['path' => 'materials/textures/wood.vmt', 'data' => str_repeat('A', 256)],
    ];

    $dirPath = VpkFixture::create($this->tempDir, $files);
    $archive = new VpkArchive($dirPath);

    // All three paths should show up in files().
    $listed = $archive->files();
    sort($listed);
    expect($listed)->toBe(['docs/readme.md', 'hello.txt', 'materials/textures/wood.vmt']);

    // Full extract should write each payload to disk at the correct relative path.
    $outDir = $this->tempDir.'/out';
    mkdir($outDir, 0777, true);
    $archive->extractTo($outDir);

    foreach ($files as $file) {
        $extracted = $outDir.'/'.$file['path'];
        expect(file_exists($extracted))->toBeTrue("missing extracted file: {$file['path']}");
        expect(file_get_contents($extracted))->toBe($file['data']);
    }

    $archive->close();
});

it('extracts a single named file via extractFileTo', function (): void {
    $files = [
        ['path' => 'a/one.txt', 'data' => 'ONE'],
        ['path' => 'a/two.txt', 'data' => 'TWO'],
    ];

    $dirPath = VpkFixture::create($this->tempDir, $files);
    $archive = new VpkArchive($dirPath);

    $outDir = $this->tempDir.'/out';
    mkdir($outDir, 0777, true);

    expect($archive->extractFileTo('a/two.txt', $outDir))->toBeTrue();
    expect(file_get_contents($outDir.'/a/two.txt'))->toBe('TWO');
    // Only the requested file should have been materialised.
    expect(file_exists($outDir.'/a/one.txt'))->toBeFalse();

    $archive->close();
});

it('silently skips extraction when the backing archive chunk is missing', function (): void {
    $files = [['path' => 'orphan.txt', 'data' => 'data']];
    $dirPath = VpkFixture::create($this->tempDir, $files);

    // Remove the _000.vpk so openArchive() has nothing to point at.
    unlink($this->tempDir.'/test_000.vpk');

    $archive = new VpkArchive($dirPath);
    // Parsing still works — only extraction should fail.
    expect($archive->files())->toBe(['orphan.txt']);

    $outDir = $this->tempDir.'/out';
    mkdir($outDir, 0777, true);
    expect($archive->extractFileTo('orphan.txt', $outDir))->toBeFalse();
    expect(file_exists($outDir.'/orphan.txt'))->toBeFalse();

    $archive->close();
});
