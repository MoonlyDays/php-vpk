# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP library implementing Valve's VPK archive format (used by Source engine games like TF2, CS:S, HL2). Published as `moonlydays/php-vpk` on Packagist. Requires PHP 8.2+. No runtime dependencies.

## Commands

- `composer test` — run the Pest 3 suite (`tests/Unit` + `tests/Feature`). Run a single file with `vendor/bin/pest tests/Unit/BinaryStreamTest.php`, or filter by name with `vendor/bin/pest --filter='round-trips a synthetic archive'`.
- `composer lint` — run Laravel Pint (parallel). The `pint.json` preset is strict: `declare(strict_types=1)`, strict comparisons, ordered class elements, global namespace imports for classes/constants/functions, and **`mb_str_functions`** (Pint will rewrite `strlen`/`substr`/etc. to their `mb_*` equivalents — be mindful that `mb_strlen` counts characters, not bytes, which matters when handling binary payloads). Run it after any code change.

The Pest suite generates all its VPK fixtures at runtime via `tests/Support/VpkFixture.php`, which builds deterministic `*_dir.vpk` + `*_000.vpk` pairs in a temp dir. There is intentionally no real VPK file checked into the repo — if you're chasing a regression against a real Valve-produced archive, drop one into a temp location and point a throwaway test at it rather than committing the binary.

## Architecture

VPK is a split-archive format: a `<name>_dir.vpk` file holds the header and directory tree, and the actual file payloads live in sibling `<name>_NNN.vpk` chunks (unless `ArchiveIndex == 0x7fff`, in which case the data is appended after the directory in the dir file itself).

The code maps directly onto that structure — there are only four classes:

- **`VpkArchive`** (`src/VpkArchive.php`) — opens a `_dir.vpk`, validates the `0x55AA1234` signature, reads version-dependent header fields (v1 adds `treeSize`; v2 adds MD5 / signature section sizes), then parses the directory tree. The tree is a triple-nested, zero-string-terminated walk of **extension → directory → filename**, where the literal space character `' '` is the sentinel for "empty extension" or "root directory" (see `VpkEntry::__construct`). Each entry's metadata (`CRC`, `PreloadBytes`, `ArchiveIndex`, `EntryOffset`, `EntryLength`, `Terminator`) is read inline. Entries are indexed into three parallel structures: `entries` (ordered), `entryByPath` (lookup), and `files` (path list for `files()`).
- **`VpkEntry`** (`src/VpkEntry.php`) — plain data object mirroring the on-disk record. Constructor reassembles the logical `path` from the `dir/name/ext` triple, handling the `' '` sentinel.
- **`BinaryStream`** (`src/BinaryStream.php`) — minimal little-endian reader (`readInt16`, `readInt32`, null-terminated `readString`, byte-at-a-time `readByte`). Owns its `fopen` handle and closes it in the destructor. Note: `readByte` uses `fgets($r, 2)` rather than `fread($r, 1)`.
- **`VpkException`** (`src/VpkException.php`) — thrown on bad signature; constructor also propagates generic `Exception` from stream I/O.

Extraction flow (`extractEntry`): archive chunk files are opened lazily and cached in `$archives` keyed by `ArchiveIndex`; missing chunks cache `false` so they're not retried. `fseek` + `fread` pulls `EntryLength` bytes from `EntryOffset`, and parent directories are created on demand. `close()` releases all cached chunk handles (the `_dir.vpk` itself is closed via `BinaryStream::__destruct`). Files already present on disk are skipped, not re-extracted.

### Things to know when editing

- Preserve the `$this->version` gating when touching header parsing — v0/v1/v2 VPKs must all still load.
- The `' '`-as-sentinel convention in `VpkEntry` is format-mandated, not a bug.
- The current implementation does **not** yet handle `PreloadBytes` (small files stored inline in the directory tree) or `ArchiveIndex == 0x7fff` (data appended after the tree). If you add extraction for those cases, the fix belongs in `extractEntry`.
- `BinaryStream::readByte` via `fgets(..., 2)` will break on bytes that `fgets` treats as line terminators — prefer `fread` if you modify it.
