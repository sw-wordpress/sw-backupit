# Security model

Backups contain credentials, personal data and `wp-config.php`. They are treated as confidential.

| Threat | Measure | Test |
|---|---|---|
| Unauthorised REST access | explicit `permission_callback` per route with granular capabilities (`swbi_*`); cookie auth needs the REST nonce (CSRF) | `PluginTest::test_every_route_requires_authentication_and_capability` |
| Public backup download | no public URLs; downloads stream through an authorised REST endpoint (`swbi_download`); staging dir outside the web root by default, `.htaccess`/`index.php` as defence in depth, health warning if inside the web root | health check |
| Zip slip / path traversal | every index path validated (`PathGuard`: no absolute paths, `..`, backslash, NUL, control chars), resolved below the canonical target | `RestoreSecurityTest::testZipSlip…`, `testMaliciousIndexPathsAreRejected` |
| Symlink attacks | existing symlinks on the write path abort the restore; symlinks from backups are never recreated | `testExistingSymlinkInTargetIsNeverFollowed`, `testSymlinksFromBackupAreNotRecreated` |
| Zip bombs | reads bounded by the index size, per-entry/total/entry-count limits, compression ratio check | `testZipBombRatioIsRefused`, `testSizeLimitsAreEnforced` |
| Overwriting arbitrary server files | restore needs the *canonical target path* typed as confirmation (and the DB name), target must not contain the backup | `testRestoreRefusesUnconfirmedTargetPath` |
| Tampered backup | SHA-256 of index, volumes, database parts bound to the manifest; verification before restore | `testTamperedIndexIsCaughtByVerification` |
| Half-written artifacts | volumes/parts/manifest are written to temp names, fsynced, renamed; manifest last | `JobEngineTest::testCompletedVolumes…` |
| SQL injection | `$wpdb->prepare`, identifiers only from fixed allowlists/`SHOW` results (quoted) | WPCS DB sniffs |
| Unsafe deserialisation | backups are never `unserialize`d; URL migration uses a parser that does not instantiate objects | Phase 2 |
| Secrets in logs | `Redactor` removes secret-like keys and URL credentials from all job events | `JobEngineTest::testEventLogIsBoundedAndRedacted` |
| Stored credentials | storage-target credentials encrypted with libsodium secretbox (key option + WP salts). Honest limit: an attacker who can read the DB *and* `wp-config.php` can decrypt them | `PluginTest::test_secrets_roundtrip` |
| Hook misuse | before_* events may veto (blocking), all others are isolated; no shell commands can be configured from the UI | `JobEngineTest` hook tests |
| Restore code execution | restored PHP files are never included/executed by the restore; `wp-config.php` is edited as text | `WpConfigEditor` |

## Not promised

No atomic file+database snapshots, no guaranteed rollback of a failed restore (strategy: verify first, atomic per-file
replacement, resumable phases), and a successful backup job does not prove that a restore will work: use the verification
and restore-test features.
