# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP library implementing Valve's VPK archive format (used by Source engine games like TF2, CS:S, HL2). Published as `moonlydays/php-vpk` on Packagist. Requires PHP 8.5+ and `ext-mbstring`. No runtime package dependencies.

## Commands

- `composer test` — run the Pest 5 suite (`tests/Unit` + `tests/Feature`). Run a single file with `vendor/bin/pest tests/Unit/BinaryStreamTest.php`, or filter by name with `vendor/bin/pest --filter='round-trips a synthetic archive'`.
- `composer lint` — run Laravel Pint (parallel), then Rector (`rector.php`: PHP-version sets plus dead-code, coding-style and type-declaration sets, over `src/` only). The `pint.json` preset is strict: `declare(strict_types=1)`, strict comparisons, ordered class elements, global namespace imports for classes/constants/functions, and **`mb_str_functions`** (Pint will rewrite `strlen`/`substr`/etc. to their `mb_*` equivalents — be mindful that `mb_strlen` counts characters, not bytes, which matters when handling binary payloads). Run it after any code change.

The Pest suite generates all its VPK fixtures at runtime via `tests/Support/VpkFixture.php`, which builds deterministic `*_dir.vpk` + `*_000.vpk` pairs in a temp dir. There is intentionally no real VPK file checked into the repo — if you're chasing a regression against a real Valve-produced archive, drop one into a temp location and point a throwaway test at it rather than committing the binary.

## Architecture

VPK is a split-archive format: a `<name>_dir.vpk` file holds the header and directory tree, and the actual file payloads live in sibling `<name>_NNN.vpk` chunks (unless `ArchiveIndex == 0x7fff`, in which case the data is appended after the directory in the dir file itself).

The code maps directly onto that structure — there are only four classes:

- **`VpkArchive`** (`src/VpkArchive.php`) — opened via the `VpkArchive::fromPath()` named constructor (the public constructor does the same); opens a `_dir.vpk`, validates the `0x55AA1234` signature, reads version-dependent header fields (v1 adds `treeSize`; v2 adds MD5 / signature section sizes), then parses the directory tree. The tree is a triple-nested, zero-string-terminated walk of **extension → directory → filename**, where the literal space character `' '` is the sentinel for "empty extension" or "root directory" (see `VpkEntry::__construct`). Each entry's metadata (`CRC`, `PreloadBytes`, `ArchiveIndex`, `EntryOffset`, `EntryLength`, `Terminator`) is read inline. Entries are indexed into three parallel structures: `entries` (ordered), `entryByPath` (lookup), and `files` (path list for `files()`).
- **`VpkEntry`** (`src/VpkEntry.php`) — plain data object mirroring the on-disk record. Constructor reassembles the logical `path` from the `dir/name/ext` triple, handling the `' '` sentinel.
- **`BinaryStream`** (`src/BinaryStream.php`) — minimal `readonly` little-endian reader (`readInt16`, `readInt32`, `readByte`, null-terminated `readString`, raw `read`, plus `tell`/`seek`/`skip`/`resource`). Owns its `fopen` handle and closes it in the destructor. Binary length checks use `mb_strlen($s, '8bit')` because Pint rewrites `strlen`.
- **`VpkException`** (`src/VpkException.php`) — plain `Exception` subclass, thrown on a bad signature, an unreadable `_dir.vpk`, or an unexpected end of file in `BinaryStream`.

Extraction flow (`extractEntry`): archive chunk files are opened lazily and cached in `$archives` keyed by `ArchiveIndex`; missing chunks cache `false` so they're not retried. Each segment from `segments()` is copied with `fseek` + `stream_copy_to_stream` into a `<file>.part` temp file that is renamed into place on success (and deleted on failure); parent directories are created on demand. `close()` releases all cached chunk handles (the `_dir.vpk` itself is closed via `BinaryStream::__destruct`). Files already present on disk are skipped unless `$overwrite` is true, and `extractFileTo` rejects paths that would escape the target directory.

### Things to know when editing

- Preserve the `$this->version` gating when touching header parsing — v0/v1/v2 VPKs must all still load.
- The `' '`-as-sentinel convention in `VpkEntry` is format-mandated, not a bug.
- An entry's data is described by `VpkArchive::segments()`: optional preload bytes (inline in the dir file at `VpkEntry::$preloadOffset`) followed by `EntryLength` bytes from either a chunk file or, when `ArchiveIndex == 0x7fff`, the dir file after the tree (`$dataOffset`). All reading/extraction/CRC checks go through it.
- Extraction must stay streaming: use `stream_copy_to_stream` / `hash_update_stream`, never `fread` whole payloads. Always `fseek` first — `stream_copy_to_stream` silently ignores an offset of `0`.
- Rector's dead-code set deletes private properties without getters (it once removed the header parsing). Keep header fields exposed via public accessors.
