# php-vpk

A pure-PHP library for reading Valve's [VPK](https://developer.valvesoftware.com/wiki/VPK) archive format — the package format used by Source engine games such as Team Fortress 2, Counter-Strike: Source, and Half-Life 2.

Supports VPK v1 and v2 directory files, including preload data and file data embedded in the `_dir.vpk` itself. No native extensions required beyond `ext-mbstring`.

## Installation

```bash
composer require moonlydays/php-vpk
```

Requires PHP 8.5 or newer.

## Usage

```php
use MoonlyDays\VPK\VpkArchive;
use MoonlyDays\VPK\VpkEntry;

$archive = VpkArchive::fromPath('/path/to/tf2_misc_dir.vpk');

// List every file in the archive
foreach ($archive as $path => $entry) {
    echo $path, ' (', $entry->size(), ' bytes)', PHP_EOL;
}

// Look up and read a single file (paths are case-insensitive, either slash style)
if ($archive->has('materials/console/background01.vmt')) {
    $contents = $archive->read('materials/console/background01.vmt');
}

// Find files by glob
$materials = $archive->find('materials/*.vmt');

// Extract a single file, preserving its archive path
$archive->extractFileTo('materials/console/background01.vmt', __DIR__ . '/out');

// Or extract everything (optionally filtered)
$archive->extractTo(__DIR__ . '/out', fn (VpkEntry $entry) => $entry->extension === 'vmt');

$archive->close();
```

### How VPK files are laid out

A VPK archive is split across multiple files on disk:

- `<name>_dir.vpk` — holds the header and the directory tree
- `<name>_000.vpk`, `<name>_001.vpk`, ... — hold the actual file payloads

You always open the `_dir.vpk` file. The sibling chunk files must live in the same directory; the library opens them lazily as needed and caches the handles until `close()` is called.

## API

Methods that take an entry accept either an in-archive path (`string`) or a `VpkEntry`.

### `MoonlyDays\VPK\VpkArchive`

**Opening and header**

| Method | Description |
| --- | --- |
| `static fromPath(string $vpkPath): self` | Open a `_dir.vpk`. Throws `VpkException` if the file can't be read or the header signature is invalid. |
| `path(): string` | Path of the `_dir.vpk` file. |
| `version(): int` | VPK format version. |
| `treeSize()`, `fileDataSectionSize()`, `archiveMd5SectionSize()`, `otherMd5SectionSize()`, `signatureSectionSize()` | Header section sizes in bytes (0 when the version doesn't define them). |

**Browsing**

| Method | Description |
| --- | --- |
| `entries(): list<VpkEntry>` | Every entry, in directory order. |
| `files(): list<string>` | Every file path. |
| `has(string $path): bool` | Whether a path exists. Case-insensitive; accepts `/` or `\`. |
| `entry(string $path): ?VpkEntry` | Look up an entry by path. |
| `count(): int` | Number of entries. The archive is also `Countable` and iterable as `path => VpkEntry`. |
| `directories(): list<string>` | Unique directory paths (`''` is the root). |
| `extensions(): list<string>` | Unique extensions, without the leading dot. |
| `filesIn(string $directory, bool $recursive = false): list<VpkEntry>` | Entries in a directory, optionally including subdirectories. |
| `find(string $pattern): list<VpkEntry>` | Entries matching a case-insensitive glob such as `materials/*.vmt`. `*` also matches across `/`. |
| `filter(callable $callback): list<VpkEntry>` | Entries accepted by `$callback(VpkEntry): bool`. |
| `totalSize(): int` | Combined size of every file, in bytes. |

**Reading**

| Method | Description |
| --- | --- |
| `read($entry): string` | Full contents as a string. Convenient for small files. |
| `stream($entry): resource` | A rewound, readable `php://temp` stream with the contents. The caller should `fclose` it. |
| `writeTo($entry, resource $destination): void` | Stream the contents into an open, writable stream without buffering them in memory. |
| `verify($entry): bool` | Check the contents against the stored CRC32. Returns `false` on a mismatch. |

**Extracting**

| Method | Description |
| --- | --- |
| `extractFileTo($entry, string $targetDir, bool $overwrite = false): void` | Extract one file into `$targetDir`, preserving its archive path. Refuses paths that would escape `$targetDir`. |
| `extractFileAs($entry, string $destination, bool $overwrite = false): void` | Extract one file to an explicit file path. |
| `extractTo(string $targetDir, ?callable $filter = null, bool $overwrite = false): int` | Extract every file (or those accepted by `$filter`). Returns the number of files extracted or already present. |
| `close(): void` | Close cached chunk-file handles. Safe to call repeatedly; handles are reopened on demand. |

Reading and extraction methods throw a `VpkException` when the path is unknown, its chunk file is missing, its data is truncated, or the destination can't be written; `extractTo` stops at the first failure. Files that already exist on disk are left untouched unless `$overwrite` is `true`.

### `MoonlyDays\VPK\VpkEntry`

A single file record. Public properties: `path`, `directory`, `name`, `extension`, plus the raw on-disk fields `CRC`, `PreloadBytes`, `ArchiveIndex`, `EntryOffset`, `EntryLength` and `Terminator`.

| Method | Description |
| --- | --- |
| `fileName(): string` | Name with extension, e.g. `wood.vmt`. |
| `size(): int` | Total size in bytes (preload data + archived data). |
| `hasPreloadData(): bool` | Whether part of the file is stored inline in the directory. |
| `isStoredInDirectory(): bool` | Whether the data lives in the `_dir.vpk` itself rather than a numbered chunk. |
| `crcHex(): string` | CRC as 8 lowercase hex characters, matching `hash('crc32b', ...)`. |
| `isIn(string $directory, bool $recursive = false): bool` | Whether the entry lives in `$directory` (or below it). |

### `MoonlyDays\VPK\VpkException`

Thrown with a descriptive message when a `_dir.vpk` can't be opened, has an invalid header signature or ends unexpectedly, and when reading or extracting an entry fails (unknown path, missing or truncated chunk, unwritable destination, or a path that would escape the target directory).

## Development

```bash
composer install
composer test    # run the Pest suite
composer lint    # run Laravel Pint and Rector
```

The test suite generates all VPK fixtures at runtime, so no binary test data is committed to the repository.

## License

Released under the MIT License.
