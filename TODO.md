# TODO

See `docs/implementation-plan.md` for context. `[x]` items are mirrored in `DONE.md`.

## Phase 1 – Core / MVP
- [x] Repo, composer, tooling skeleton
- [ ] Core domain model (enums, profile, job, state machine)
- [ ] Support layer (path guard, atomic file writes, resource budget)
- [ ] File scanner + rules + NDJSON index
- [ ] Database exporter (streaming, resumable)
- [ ] ZIP volume writer/reader/verifier (ZIP64, chunking)
- [ ] Manifest manager v1
- [ ] Backup engine + job runner (checkpoints, lease, pause/cancel)
- [ ] Standalone restore CLI (verify, extract, DB import, wp-config)
- [ ] Restore web wizard (token auth)
- [ ] WP adapter: bootstrap, schema, repositories, REST, scheduler, WP-CLI
- [ ] Admin SPA (Vue/Quasar) + build into plugin ZIP
- [ ] E2E test: backup → wipe → restore
- [ ] Docs (user guide, architecture, backup format, security, wp-cli, development)

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
