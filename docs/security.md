# Security model

Backups contain credentials, personal data and `wp-config.php`. They are treated as confidential.

| Threat | Measure | Test |
|---|---|---|
| Unauthorised REST access | explicit `permission_callback` per route with granular capabilities (`swbi_*`); cookie auth needs the REST nonce (CSRF) | `PluginTest::test_every_route_requires_authentication_and_capability` |
| Public backup download | no public URLs; downloads stream through an authorised REST endpoint (`swbi_download`); staging dir outside the web root by default, `.htaccess`/`index.php` as defence in depth, health warning if inside the web root | health check |
| Zip slip / path traversal | every index path validated (`PathGuard`: no absolute paths, `..`, backslash, NUL, control chars), resolved below the canonical target | `RestoreSecurityTest::testZipSlip…`, `testMaliciousIndexPathsAreRejected` |
| Symlink attacks | existing symlinks on the write path abort the restore; symlinks from backups are never recreated (listed in the result so they can be recreated manually) | `testExistingSymlinkInTargetIsNeverFollowed`, `testSymlinksFromBackupAreNotRecreated` |
| Zip bombs | reads bounded by the index size, per-entry/total/entry-count limits, compression ratio check | `testZipBombRatioIsRefused`, `testSizeLimitsAreEnforced` |
| Overwriting arbitrary server files | restore needs the *canonical target path* typed as confirmation (and the DB name), target must not contain the backup | `testRestoreRefusesUnconfirmedTargetPath` |
| Tampered backup | SHA-256 of index, volumes, database parts bound to the manifest; verification before restore | `testTamperedIndexIsCaughtByVerification` |
| Half-written artifacts | volumes/parts/manifest are written to temp names, fsynced, renamed; manifest last | `JobEngineTest::testCompletedVolumes…` |
| SQL injection | `$wpdb->prepare`, identifiers only from fixed allowlists/`SHOW` results (quoted) | WPCS DB sniffs |
| Unsafe deserialisation | backups are never `unserialize`d; URL migration parses the serialization format itself (no objects, no autoload); S3 resume state is JSON | `SerializedReplacerTest::testObjectsArePreservedWithoutInstantiation` |
| SSRF via storage endpoints | hosts resolving to loopback/private/link-local/metadata ranges are refused unless the target opts in; no userinfo in URLs, http only with opt-in, redirects disabled, TLS verification on | `StorageTest::testSsrfPolicyBlocksDangerousEndpoints`, WP `test_storage_target_credentials…` |
| Restored copy talks to the original database | wp-config constants are rewritten only when they are plain literals; otherwise the restore result names the constants to check. View definitions lose the source database qualifier | `MigrationTest` |
| Weak/forged encrypted files | SWBE1 authenticated streaming encryption; header bound to every chunk, FINAL tag, bounded KDF cost on read; wrong key never creates output | `CryptoTest` |
| Restore wizard abuse | one-time token stored as hash (exactly one login per token, enforced by an exclusively created marker file; the session of that login stays valid), expiry, global rate limit (5 failures / 15 min), HttpOnly SameSite=Strict session, CSRF on every POST, strict CSP (no inline script/style), typed confirmation of target path and database, self-lock and removal after success, credentials purged from the session after the run | `WebWizardTest` |
| Tampered chains | verify checks every base backup of a delta chain; restore refuses a missing/damaged base before writing | `IncrementalChainTest` |
| Lost audit trail | append-only audit table (create/download/delete/restore/config changes), no secrets, only `REMOTE_ADDR` as source address | WP `EnterprisePhaseTest::test_audit_log…` |
| Accidental deletion of replicas | retention never deletes protected/needed backups or immutable/lock-period targets; a failing target keeps the backup; S3 Object Lock optional | `RetentionTest`, WP `test_immutable…` |
| Pre-update hook loops/blocking | lock + recency window + 20 s wait limit, blocks only when explicitly configured | WP `test_pre_update_backup…` |
| Secrets in logs | `Redactor` removes secret-like keys and URL credentials from all job events | `JobEngineTest::testEventLogIsBoundedAndRedacted` |
| Stored credentials | storage-target credentials encrypted with libsodium secretbox (key option + WP salts). Honest limit: an attacker who can read the DB *and* `wp-config.php` can decrypt them | `PluginTest::test_secrets_roundtrip` |
| Hook misuse | before_* events may veto (blocking), all others are isolated; no shell commands can be configured from the UI | `JobEngineTest` hook tests |
| Restore code execution | restored PHP files are never included/executed by the restore; `wp-config.php` is edited as text | `WpConfigEditor` |

## Residual risks and honest limits

* Credentials of storage targets are encrypted with a key stored in the same WordPress installation; an attacker who can read both the options table and `wp-config.php` can decrypt them.
* Encryption keys stored via the UI live in the database. For real protection against a compromised site keep keys outside (`SWBACKUPIT_KEY_<ID>`, environment) and in a password manager.
* The restore wizard is reachable on the web while a token is valid; use HTTPS, a short lifetime and remove it afterwards (it does so itself after success).
* S3 Object Lock is applied for uploads but only verified against an S3 mock for single-part objects; test your provider before relying on it.
* A successful test restore proves restorability of data, not correct behaviour of the restored site.

## Not promised

No atomic file+database snapshots, no guaranteed rollback of a failed restore (strategy: verify first, atomic per-file
replacement, resumable phases), and a successful backup job does not prove that a restore will work: use the verification
and restore-test features.
