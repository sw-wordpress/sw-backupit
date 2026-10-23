# TODO

See `docs/implementation-plan.md` for context. `[x]` items are mirrored in `DONE.md`.

## Phase 1 – Core / MVP
- [x] Repo, composer, tooling (PHPUnit, PHPStan L8, PHPCS PSR-12 + WPCS)
- [x] Core domain model, job state machine, repositories (file + wpdb)
- [x] Support layer (path guard, atomic writes, resource budget)
- [x] File scanner (resumable, canonical order), rules
- [x] Database exporter (streaming, resumable, optional consistent snapshot)
- [x] ZIP64 volumes, chunking of huge files, verification
- [x] Manifest v1 + file index
- [x] Backup runner (checkpoints, lease, pause/cancel/retry)
- [x] Standalone restore engine + CLI (verify, list, restore, selective, dry-run)
- [x] WP adapter: bootstrap, schema, repositories, REST, scheduler, WP-CLI
- [x] Admin SPA (Vue/Quasar) built to assets/admin
- [x] E2E backup → wipe → restore, security and reliability tests
- [x] Docs (user guide, architecture, backup format, security, wp-cli, development)
- [ ] Restore web wizard (token auth) – scheduled with Phase 2
- [ ] Add docblocks to all adapter methods (documented WPCS exception, `phpcs-wordpress.xml.dist`)
- [ ] Adapt SPA to final API (real phase names, schedule weekday/monthday, retry, target_path) – after Phase 2 API is complete

## Phase 2 – Storage & Recovery
- [ ] Flysystem storage manager, local/SFTP/S3, capability interfaces
- [ ] Multi-target upload, retry/backoff, remote verification
- [ ] Retention (count/age/GFS), dry-run
- [ ] Encryption (sodium secretstream) + restore decryption
- [ ] URL/path migration (serialized-safe), selective restore, compare
- [ ] Notifications (WP mail, dedupe)

## Phase 3 – Advanced backup
- [ ] Incremental/differential, chains, chain-aware retention
- [ ] Chunked/multipart upload, full resume, hook system

## Phase 4 – Enterprise
- [ ] Audit log, restore test automation, health monitoring
- [ ] Multisite, immutable storage, pre-update backups
- [ ] Load/security tests
