<?php

declare(strict_types=1);

use MoonlyDays\VPK\VpkArchive;
use MoonlyDays\VPK\VpkException;
use Tests\Support\VpkFactory;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/php-vpk-test-'.bin2hex(random_bytes(6));
    mkdir($this->tempDir, 0777, true);
});

afterEach(function (): void {
    VpkFactory::cleanup($this->tempDir);
});

it('rejects a file whose header signature is not 0x55AA1234', function (): void {
    $path = $this->tempDir.'/bogus_dir.vpk';
    file_put_contents($path, pack('V', 0xDEADBEEF).pack('V', 1).pack('V', 0)."\0\0\0");

    expect(fn (): VpkArchive => new VpkArchive($path))->toThrow(VpkException::class);
});

it('returns false from extractFileTo for a path that is not in the archive', function (): void {
    $dirPath = VpkFactory::create($this->tempDir, [
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

    $dirPath = VpkFactory::create($this->tempDir, $files);
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
        expect(file_exists($extracted))->toBeTrue("missing extracted file: {$file['path']}")
            ->and(file_get_contents($extracted))->toBe($file['data']);
    }

    $archive->close();
});

it('extracts a single named file via extractFileTo', function (): void {
    $files = [
        ['path' => 'a/one.txt', 'data' => 'ONE'],
        ['path' => 'a/two.txt', 'data' => 'TWO'],
    ];

    $dirPath = VpkFactory::create($this->tempDir, $files);
    $archive = new VpkArchive($dirPath);

    $outDir = $this->tempDir.'/out';
    mkdir($outDir, 0777, true);

    expect($archive->extractFileTo('a/two.txt', $outDir))->toBeTrue()
        ->and(file_get_contents($outDir.'/a/two.txt'))->toBe('TWO')
        ->and(file_exists($outDir.'/a/one.txt'))->toBeFalse();

    $archive->close();
});

it('silently skips extraction when the backing archive chunk is missing', function (): void {
    $files = [['path' => 'orphan.txt', 'data' => 'data']];
    $dirPath = VpkFactory::create($this->tempDir, $files);

    // Remove the _000.vpk so openArchive() has nothing to point at.
    unlink($this->tempDir.'/test_000.vpk');

    $archive = new VpkArchive($dirPath);
    // Parsing still works — only extraction should fail.
    expect($archive->files())->toBe(['orphan.txt']);

    $outDir = $this->tempDir.'/out';
    mkdir($outDir, 0777, true);
    expect($archive->extractFileTo('orphan.txt', $outDir))->toBeFalse()
        ->and(file_exists($outDir.'/orphan.txt'))->toBeFalse();

    $archive->close();
});

it('parses the v1 header fields', function (): void {
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir, [['path' => 'a.txt', 'data' => 'x']]));

    expect($archive->version())->toBe(1);
    expect($archive->treeSize())->toBeGreaterThan(0);
});

it('exposes lookup, counting, and iteration helpers', function (): void {
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir, [
        ['path' => 'materials/wood.vmt', 'data' => 'WOOD'],
        ['path' => 'materials/brick/red.vmt', 'data' => 'RED'],
        ['path' => 'scripts/game.txt', 'data' => 'GAME'],
        ['path' => 'LICENSE', 'data' => 'MIT'],
    ]));

    expect($archive)->toHaveCount(4)
        ->and($archive->has('scripts/game.txt'))->toBeTrue()
        ->and($archive->has('SCRIPTS\\Game.TXT'))->toBeTrue()
        ->and($archive->has('nope.txt'))->toBeFalse()
        ->and($archive->entry('materials/wood.vmt')?->fileName())->toBe('wood.vmt')
        ->and($archive->entry('nope.txt'))->toBeNull()
        ->and($archive->directories())->toEqualCanonicalizing(['', 'materials', 'materials/brick', 'scripts'])
        ->and($archive->extensions())->toEqualCanonicalizing(['txt', 'vmt']);

    $paths = fn (array $entries): array => array_map(fn ($e) => $e->path, $entries);
    expect($paths($archive->filesIn('materials')))->toBe(['materials/wood.vmt'])
        ->and($paths($archive->filesIn('materials', recursive: true)))->toEqualCanonicalizing(['materials/wood.vmt', 'materials/brick/red.vmt'])
        ->and($paths($archive->find('materials/*.vmt')))->toEqualCanonicalizing(['materials/wood.vmt', 'materials/brick/red.vmt'])
        ->and($archive->totalSize())->toBe(4 + 3 + 4 + 3)
        ->and(array_keys(iterator_to_array($archive)))->toEqualCanonicalizing($archive->files());
});

it('reads, streams, and verifies entry contents', function (): void {
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir, [
        ['path' => 'a.txt', 'data' => 'alpha'],
    ]));

    expect($archive->read('a.txt'))->toBe('alpha')
        ->and($archive->read('missing.txt'))->toBeNull();

    $stream = $archive->stream('a.txt');
    expect(stream_get_contents($stream))->toBe('alpha');
    fclose($stream);

    $sink = fopen('php://memory', 'w+b');
    expect($archive->writeTo('a.txt', $sink))->toBeTrue();
    rewind($sink);
    expect(stream_get_contents($sink))->toBe('alpha')
        ->and($archive->verify('a.txt'))->toBeTrue();

    $entry = $archive->entry('a.txt');
    $entry->CRC ^= 1;
    expect($archive->verify($entry))->toBeFalse();
});

it('handles preload bytes and data embedded in the dir file', function (): void {
    $files = [
        ['path' => 'split.txt', 'data' => 'HEADtail', 'preload' => 4],
        ['path' => 'all_preload.txt', 'data' => 'tiny', 'preload' => 4],
        ['path' => 'embedded.txt', 'data' => 'in the dir file', 'inline' => true],
        ['path' => 'both.txt', 'data' => 'PRErest', 'preload' => 3, 'inline' => true],
        ['path' => 'chunk.txt', 'data' => 'chunked'],
    ];
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir, $files));

    expect($archive->entry('embedded.txt')->isStoredInDirectory())->toBeTrue()
        ->and($archive->entry('split.txt')->hasPreloadData())->toBeTrue();

    $outDir = $this->tempDir.'/out';
    expect($archive->extractTo($outDir))->toBe(count($files));

    foreach ($files as $file) {
        expect(file_get_contents($outDir.'/'.$file['path']))->toBe($file['data'])
            ->and($archive->verify($file['path']))->toBeTrue();
    }
});

it('extracts only filtered entries and to explicit destinations', function (): void {
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir, [
        ['path' => 'a/keep.vmt', 'data' => 'K'],
        ['path' => 'a/skip.txt', 'data' => 'S'],
    ]));

    $outDir = $this->tempDir.'/out';
    expect($archive->extractTo($outDir, fn ($e): bool => $e->extension === 'vmt'))->toBe(1)
        ->and(file_exists($outDir.'/a/keep.vmt'))->toBeTrue()
        ->and(file_exists($outDir.'/a/skip.txt'))->toBeFalse();

    $dest = $this->tempDir.'/renamed/file.bin';
    expect($archive->extractFileAs('a/skip.txt', $dest))->toBeTrue()
        ->and(file_get_contents($dest))->toBe('S');
});

it('skips existing files unless overwrite is requested', function (): void {
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir, [['path' => 'f.txt', 'data' => 'new']]));
    $outDir = $this->tempDir.'/out';
    mkdir($outDir);
    file_put_contents($outDir.'/f.txt', 'old');

    $archive->extractFileTo('f.txt', $outDir);
    expect(file_get_contents($outDir.'/f.txt'))->toBe('old');

    $archive->extractFileTo('f.txt', $outDir, overwrite: true);
    expect(file_get_contents($outDir.'/f.txt'))->toBe('new')
        ->and(file_exists($outDir.'/f.txt.part'))->toBeFalse();
});

it('refuses to extract entries that would escape the target directory', function (): void {
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir.'/vpk', [['path' => '../evil.txt', 'data' => 'x']]));

    expect($archive->extractFileTo('../evil.txt', $this->tempDir.'/out'))->toBeFalse()
        ->and(file_exists($this->tempDir.'/evil.txt'))->toBeFalse();
});

it('can be closed repeatedly and still extract afterwards', function (): void {
    $archive = VpkArchive::fromPath(VpkFactory::create($this->tempDir, [['path' => 'f.txt', 'data' => 'data']]));

    expect($archive->read('f.txt'))->toBe('data');
    $archive->close();
    $archive->close();

    expect($archive->read('f.txt'))->toBe('data');
});
