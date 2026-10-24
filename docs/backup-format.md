# Backup format (version 1)

A backup is a directory that is **self-contained**: it can be read without WordPress, without the SWBackupIt
database tables and without the plugin (`restore` application only).

```
<backup-id>/                  UUIDv4
  manifest.json               written LAST and atomically; no manifest => incomplete backup
  files.ndjson                file index (one JSON object per line, canonical order)
  database/db-0001.sql.gz     gzip SQL parts, independently importable in order
  volumes/backup-001.zip      independent ZIP64 volumes
  work/                       temporary data, removed on finalisation
```

## manifest.json

```json
{
  "format": "swbackupit", "format_version": 1,
  "backup_id": "uuid", "job_id": "uuid",
  "created_at": "2026-10-24T02:30:00Z", "finished_at": "...",
  "type": "full|files|database|incremental|differential",
  "generator": {"name": "swbackupit", "version": "0.1.0", "php": "8.3.16"},
  "source": {"site_url": "...", "home_url": "...", "wp_version": "...", "table_prefix": "wp_", "root_path": "...", "multisite": false},
  "profile": { "...": "immutable snapshot of the profile (no secrets)" },
  "parent": null,
  "files": {"index": "files.ndjson", "sha256": "…", "count": 1234, "bytes": 99999},
  "volumes": [{"index": 1, "name": "backup-001.zip", "size": 1, "sha256": "…", "entries": 10}],
  "database": {"parts": [{"name": "db-0001.sql.gz", "size": 1, "sha256": "…", "raw_bytes": 1}],
               "tables": [{"name": "wp_posts", "type": "table", "rows": 5, "engine": "InnoDB", "exported_rows": 5}],
               "consistent_snapshot": false, "table_prefix": "wp_", "include_foreign_tables": false},
  "encryption": null,
  "checksums": true, "volume_size_bytes": 268435456, "warnings_count": 0
}
```

Rules: readers must reject a `format_version` newer than they support, validate every name against its pattern
(`backup-\d{3,}\.zip`, `db-\d{4,}\.sql\.gz`) and must never use manifest strings as filesystem paths. The index
and every volume/part are bound to the manifest by SHA-256.

## File index (`files.ndjson`)

One JSON object per line, ordered by *canonical path order* (depth-first, byte-wise sorted names; see
`PathOrder`). The order is part of the format: incremental backups merge-join two indexes.

| key | meaning |
|---|---|
| `p` | relative path (UTF-8, `/` separated) |
| `t` | `f` file, `d` directory, `l` symlink |
| `s`, `m`, `mode` | size, mtime, permissions |
| `lt` | symlink target (never recreated on restore) |
| `st` | `ok`, `unreadable`, `vanished`, `changed` (changed while being read) |
| `h` | SHA-256 of the file content (if checksums are enabled) |
| `v`, `a` | volume number and ZIP entry name |
| `c` | chunk list `[{v,a,s,h}]` for files larger than the volume size |

ZIP entry names are the path with `\ : * ? " < > | %` percent-encoded (ZipStream would otherwise rewrite them).

## Volumes and chunking

Each volume is a complete ZIP64 archive (any ZIP tool can open it). A volume is closed when it reaches the
configured size **or when the time budget of a worker tick is used up**, so volumes can be smaller than the limit.
Files larger than the volume size are split into `<entry>.swbpartNNNN` chunks, one chunk per volume; restore reassembles
and verifies each chunk and the whole file.

## Database parts

Gzip compressed SQL, parts close at statement boundaries. Every part starts with `SET NAMES utf8mb4`,
`FOREIGN_KEY_CHECKS=0`, `UNIQUE_CHECKS=0`, a permissive `sql_mode` (zero dates exist in real WordPress databases)
and UTC time zone. Binary columns are hex literals, text is escaped by the driver. Triggers/routines/events use
`DELIMITER ;;` blocks; views are retried at the end of an import (dependencies between views).

### Consistency

* Default: data is read in batches by primary key across several worker invocations. **There is no global snapshot**: rows
  written between batches may be missing or inconsistent across tables, and files/database are never an atomic pair.
* `db_consistent_snapshot`: the export runs in a *single* invocation inside `START TRANSACTION WITH CONSISTENT SNAPSHOT`
  (REPEATABLE READ). That is transactionally consistent **for InnoDB tables only**; MyISAM tables are warned about.
  It cannot be resumed (a crash restarts the export) and ignores the time budget, so use it from the CLI.
* Tables without primary key are paged with LIMIT/OFFSET (unstable under concurrent writes).

## Incremental and differential backups

Every backup's `files.ndjson` is a **complete** description of the file state at backup time. Entries whose data lives in another
backup carry `b` (the backup id that holds `v`/`a`/`c`); entries without `b` reference this backup's own volumes. Consequences:

* Restoring any point in time needs only that backup's index plus the volumes of the backups named in `b` – deletions need no special
  handling (a deleted file is simply absent), renames are stored as delete + new file (no content de-duplication).
* `chain.requires` in the manifest lists exactly those base backups; `parent` names the backup the comparison was made against
  (incremental: the previous complete backup of the profile, differential: the last non-delta backup).
* A file is "unchanged" when the base index holds its data with identical size and modification time; everything else is archived again.
  The database is always exported completely (true incremental database backups are not implemented).
* Without a usable base (none yet, or its local index is gone) a full backup is created instead and recorded with `type: full`.
* Retention treats `requires` and `parent` as dependencies and never deletes a backup that a kept backup needs; the restore verifies
  that all required bases are present (next to the backup directory) and refuses to start otherwise. `swbackupit-restore fetch --with-chain`
  downloads a backup and its whole chain from a storage target.

## Encryption (SWBE1)

Optional client side encryption of volumes, database parts and the file index (the manifest stays readable and contains no
secrets: only `encryption.key_id`, cipher and KDF names). Each file `X` becomes `X.swbe`:

```
"SWBE" | version(1) | kdf(1: 0 raw key, 1 argon2id) | reserved(0) | keyIdLen(1) | keyId
       | [salt(16) | opsLimit u32 | memLimitKiB u32]   (kdf = 1)
       | secretstream header (24)
then repeated: u32 big endian ciphertext length | ciphertext   (1 MiB plaintext per chunk)
```

* Cipher: libsodium `crypto_secretstream_xchacha20poly1305` (authenticated, streaming, constant memory).
* The complete header is bound to every chunk as additional data; the last chunk carries the FINAL tag, so truncation,
  reordering, header tampering and trailing data fail authentication. A wrong key fails on the first chunk and no plaintext
  file is created.
* Keys: a random 32 byte key (`base64:…`) or a passphrase stretched with Argon2id (default 3 ops / 64 MiB; parameters live in
  the header and are bounded on read so a malicious file cannot demand unbounded resources).
* Checksums in the manifest refer to the stored (encrypted) files, so integrity can be verified **without any key**.
* The encrypt step proves every encrypted file by a decrypt round trip against the plain checksum before the plain file is deleted.
* Key management is separate from the backup: keys come from a `SWBACKUPIT_KEY_<ID>` constant/environment variable or are stored
  (encrypted) in the options table; generated keys are shown once and must be stored by the user, because a backup must not depend
  on a secret that only exists in the database it protects.

## Upload layout on storage targets

`<prefix>/<backup-id>/{volumes/*, database/*, files.ndjson[.swbe], manifest.json}`; the manifest is uploaded last, so a remote
directory without manifest is an incomplete upload. Objects are uploaded under a temporary name and renamed (S3: atomic PUT /
multipart completion). S3 objects carry the SHA-256 as metadata so verification is a HEAD request.

## Compatibility

Every format version needs at least one automated restore compatibility test (`tests/E2e`, `tests/Integration`).
