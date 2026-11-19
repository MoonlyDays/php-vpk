# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP library implementing Valve's VPK archive format (used by Source engine games like TF2, CS:S, HL2). Published as `moonlydays/php-vpk` on Packagist. Requires PHP 8.5+ and `ext-mbstring`. No runtime package dependencies; `league/flysystem` ~3.0 is optional (`suggest`, plus `require-dev` for tests).

## Commands

- `composer test` — run the Pest 5 suite (`tests/Unit` + `tests/Feature`). Run a single file with `vendor/bin/pest tests/Unit/BinaryStreamTest.php`, or filter by name with `vendor/bin/pest --filter='round-trips a synthetic archive'`.
- `composer lint` — run Laravel Pint (parallel), then Rector (`rector.php`: PHP-version sets plus dead-code, coding-style and type-declaration sets, over `src/` only). The `pint.json` preset is strict: `declare(strict_types=1)`, strict comparisons, ordered class elements, global namespace imports for classes/constants/functions, and **`mb_str_functions`** (Pint will rewrite `strlen`/`substr`/etc. to their `mb_*` equivalents — be mindful that `mb_strlen` counts characters, not bytes, which matters when handling binary payloads). Run it after any code change.

The Pest suite generates all its VPK fixtures at runtime via `tests/Support/VpkFactory.php`, which builds deterministic `*_dir.vpk` + `*_000.vpk` pairs in a temp dir. There is intentionally no real VPK file checked into the repo — if you're chasing a regression against a real Valve-produced archive, drop one into a temp location and point a throwaway test at it rather than committing the binary.

## Architecture

VPK is a split-archive format: a `<name>_dir.vpk` file holds the header and directory tree, and the actual file payloads live in sibling `<name>_NNN.vpk` chunks (unless `ArchiveIndex == 0x7fff`, in which case the data is appended after the directory in the dir file itself).

The code maps directly onto that structure:

- **`VpkArchive`** (`src/VpkArchive.php`) — opened via `VpkArchive::fromPath()` (the public constructor does the same); validates the `0x55AA1234` signature, reads version-dependent header fields (v1 adds `treeSize`; v2 adds MD5 / signature section sizes), then parses the directory tree: a triple-nested, empty-string-terminated walk of **extension → directory → filename**. To save memory each entry is kept as a packed binary string in `$records` (keyed by normalized path, layout described by `RECORD_FORMAT`), with directories and extensions interned in `$dirs` / `$exts`; `createEntry()` builds a `VpkEntry` on demand.
- **`VpkEntry`** (`src/VpkEntry.php`) — plain data object mirroring the on-disk record. Owns the format's path rules: the `BLANK` (`' '`) sentinel for "root directory" / "no extension" (`normalizePart()`, `joinPath()`, `splitPath()`), `normalizePath()` (case-insensitive, either slash style) and `isSafePath()` (used by both extraction and the builder).
- **`BinaryStream`** (`src/BinaryStream.php`) — minimal `readonly` little-endian reader (`readInt16`, `readInt32`, `readByte`, null-terminated `readString`, raw `read`, plus `tell`/`seek`/`skip`/`resource`). Owns its handle and closes it in the destructor.
- **`VpkBuilder`** (`src/VpkBuilder.php`) — writes VPK v1 archives from files (`addFile`, `addDirectory`) and strings (`addString`). `layout()` sorts entries (ext → dir → name, for reproducible output) and streams each source once to get its CRC and size, assigning archive index and offset; `build()` then writes the chunks (if `chunkSize()` is set) and finally the dir file, streaming each source a second time. Without `chunkSize()` all data is embedded after the tree (`ArchiveIndex 0x7fff`).
- **`Filesystem`** (`src/Filesystem.php`, `@internal`) — the only place that calls warning-prone filesystem functions (`open`, `mkdir`, `rename`, `delete`, `write`, `writeFile`). It captures PHP's warning with a scoped error handler and throws a `VpkException` carrying that reason. `writeFile()` writes through a `.part` file that is renamed into place only on success; extraction and the builder both use it.
- **`VpkException`** (`src/VpkException.php`) — every failure is raised through one of its named constructors (`entryNotFound`, `chunkNotFound`, `truncatedData`, `unsafePath`, …).

`extractTo()` / `extractFileTo()` also accept a Flysystem `FilesystemOperator` (`writeEntry()` streams the entry via `stream()` into `writeStream()`, wrapping `FilesystemException` in `VpkException::flysystemFailed`). Flysystem must stay optional: only reference its types in signatures and `instanceof`/`catch`, never call into it unless the caller passed a Flysystem object.

Extraction flow (`extractEntry`): chunk files are opened lazily by `openChunk()` and cached in `$chunks` keyed by `ArchiveIndex`; a missing chunk caches `false` so it isn't retried. The entry's segments are resolved first (so a missing chunk fails before touching the disk), then copied into a `<file>.part` temp file that is moved into place on success and deleted on failure. `close()` releases the cached chunk handles (the `_dir.vpk` is closed via `BinaryStream::__destruct`). Files already on disk are skipped unless `$overwrite` is true, and `extractFileTo` rejects paths that would escape the target directory.

### Conventions

- **Errors throw, never return `false`/`null`.** Add a named constructor to `VpkException` rather than `new VpkException(...)` inline, and make the message name the file, entry or offset involved. Exceptions take explicit values (including any PHP reason string); they never read global error state. Lookups (`entry()`, `has()`) and `verify()`'s CRC result are not errors.
- **No `@` and no `error_get_last()` outside `Filesystem`.** Any new warning-prone call (`fopen`, `mkdir`, `rename`, `unlink`, …) gets a method there.
- **Binary layouts are named constants** (`METADATA_FORMAT`, `RECORD_FORMAT`, `METADATA_LENGTH`, …) unpacked with named keys, not magic byte offsets.
- **Format rules live in one place.** Sentinel handling and path normalization go through `VpkEntry`; don't compare against `' '` or lowercase paths ad hoc.
- **All entry data access goes through `segments()` + `readSegments()`**, which seeks, hands each range to a consumer and throws `truncatedData` on a short read. Clean up handles and temp files with `try`/`finally`.
- **Function names are short, plain technical English** (`open`, `mkdir`, `readSegments`, `splitPath`), not descriptive prose (`ensureDirectoryExists`, `materializeEntry`). Exception factories follow `<thing><Problem>`: `fileNotFound`, `openFailed`, `invalidPath`.
- Byte lengths use `mb_strlen($s, '8bit')` / `mb_substr(..., '8bit')`, because Pint rewrites `strlen`/`substr` to their character-counting `mb_*` versions.

### Things to know when editing

- Preserve the `$this->version` gating when touching header parsing — v0/v1/v2 VPKs must all still load.
- The `' '` sentinel is format-mandated, not a bug.
- An entry's data is described by `VpkArchive::segments()`: optional preload bytes (inline in the dir file at `VpkEntry::$preloadOffset`) followed by `EntryLength` bytes from either a chunk file or, when `ArchiveIndex == 0x7fff`, the dir file after the tree (`$dataOffset`).
- Extraction must stay streaming: use `stream_copy_to_stream` / `hash_update_stream`, never `fread` whole payloads. Always `fseek` first — `stream_copy_to_stream` silently ignores an offset of `0`.
- Rector's dead-code set deletes private properties without getters (it once removed the header parsing). Keep header fields exposed via public accessors.
