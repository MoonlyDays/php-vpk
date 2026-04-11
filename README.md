# php-vpk

A pure-PHP library for reading Valve's [VPK](https://developer.valvesoftware.com/wiki/VPK) archive format — the package format used by Source engine games such as Team Fortress 2, Counter-Strike: Source, and Half-Life 2.

Supports VPK v1 and v2 directory files. No native extensions required beyond `ext-mbstring`.

## Installation

```bash
composer require moonlydays/php-vpk
```

Requires PHP 8.2 or newer.

## Usage

```php
use MoonlyDays\VPK\VpkArchive;

$archive = new VpkArchive('/path/to/tf2_misc_dir.vpk');

// List every file in the archive
foreach ($archive->files() as $path) {
    echo $path, PHP_EOL;
}

// Extract a single file
$archive->extractFileTo('materials/console/background01.vmt', __DIR__ . '/out');

// Or extract everything
$archive->extractTo(__DIR__ . '/out');

$archive->close();
```

### How VPK files are laid out

A VPK archive is split across multiple files on disk:

- `<name>_dir.vpk` — holds the header and the directory tree
- `<name>_000.vpk`, `<name>_001.vpk`, ... — hold the actual file payloads

You always open the `_dir.vpk` file. The sibling chunk files must live in the same directory; the library opens them lazily as needed during extraction and caches the handles until `close()` is called.

## API

### `MoonlyDays\VPK\VpkArchive`

| Method | Description |
| --- | --- |
| `__construct(string $filePath)` | Open a `_dir.vpk`. Throws `VpkException` if the header signature is invalid. |
| `files(): array` | Return the list of every file path contained in the archive. |
| `extractFileTo(string $path, string $targetDir): bool` | Extract a single file by its in-archive path. Returns `false` if the path is unknown or the backing chunk file is missing. Existing files on disk are left untouched. |
| `extractTo(string $targetDir): void` | Extract every file in the archive into `$targetDir`, preserving the directory structure. |
| `close(): void` | Close all cached chunk-file handles. |

### `MoonlyDays\VPK\VpkException`

Thrown by the `VpkArchive` constructor when the input file does not have a valid VPK header signature.

## Development

```bash
composer install
composer test    # run the Pest 3 suite
composer lint    # run Laravel Pint
```

The test suite generates all VPK fixtures at runtime, so no binary test data is committed to the repository.

## License

Released under the MIT License.
