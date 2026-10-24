# TODO

Open and future work. Everything else is listed in `DONE.md`. See `docs/implementation-plan.md` for context.

## Known gaps (explicitly not implemented)
- [ ] **Real SFTP integration test** – no SSH server was available in the development environment; SFTP is covered by configuration/SSRF/host-key validation tests only (the Flysystem adapter is the library's own, shared contract code with the local adapter).
- [ ] **S3 Object Lock for multipart uploads** is sent with `CreateMultipartUpload` but was only verified for single-part objects (the S3 mock does not return lock data for multipart); verify with your provider.
- [ ] **Resumable local/SFTP uploads** – only S3 resumes within a file (multipart parts); local/SFTP restart the file (finished files are never repeated).
- [ ] **True incremental database backups** (binlog based) – database is exported completely on every run (as allowed by the spec).
- [ ] **Selective restore of single sites of a multisite network** (full network backup/restore works).
- [ ] **Content de-duplication of renamed files** in incremental backups (renamed files are stored again).
- [ ] **Native-speaker review** of the machine-assisted translations (de, fr, pl, it); uncertain strings were noted in the translators' reports (e.g. technical terms kept in English).
- [ ] **Docblocks for all adapter methods** (documented WPCS relaxation in `phpcs-wordpress.xml.dist`; typed signatures carry the contract).
- [ ] SSE for live progress (REST polling is implemented; SSE was optional).
- [ ] Database part download count in the backup resource (`database_part_count`) so the UI can offer all parts.
- [ ] Upload parallelism setting (`max_parallel_uploads` is stored but uploads are sequential).
- [ ] **Restore success/failure e-mails for real restores** – restores run in the standalone application without WordPress, so there is no
  hook point; only test restores send mails.
- [ ] **Per-schedule notification settings** (notifications are configured per profile; a per-schedule override needs a schema change).
- [ ] **Selective database restore in the web wizard** – available in the restore CLI (`--tables`) and the engine; the wizard restores all tables.
- [ ] **Web server matrix test** that staging and restore-state directories are not served (Apache/nginx/IIS, WordPress in a subdirectory);
  directories inside the web root are refused by default and flagged by the health check, the nginx rule is in the user guide.
- [ ] **max_allowed_packet pre-check** before a restore (a too large row currently stops the import with an explicit message; resumable).
- [ ] Tables without primary key are exported in physical order with LIMIT/OFFSET (unstable under concurrent writes); MyISAM tables can
  repeat a single statement after a crash during import.
- [ ] Staging Directory per default in wp-content/uploads/sw-backupit and with a Deny from all .htaccess and an index.html, which is empty
- [ ] **Transfer notifications** – copy/move failures are logged, shown in the backup list and fired as `swbi_backup_transfer_failed`, but
  no e-mail is sent; transfers run one after another (no parallel uploads) and a large single file restarts after a crash (S3: per part).

## Ideas
- [ ] Per-site backups on multisite; WebDAV/FTP adapters as optional Composer packages; restore test scheduling UI polish.
- [ ] Hosting matrix tests (PHP-FPM worker kills, very low `memory_limit`, restrictive open_basedir).
