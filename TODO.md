# TODO

Open and future work. Everything else is listed in `DONE.md`. See `docs/implementation-plan.md` for context.

## Known gaps (explicitly not implemented)
- [ ] **Real SFTP integration test** – no SSH server was available in the development environment; SFTP is covered by configuration/SSRF/host-key validation tests only (the Flysystem adapter is the library's own, shared contract code with the local adapter).
- [ ] **S3 Object Lock for multipart uploads** is sent with `CreateMultipartUpload` but was only verified for single-part objects (the S3 mock does not return lock data for multipart); verify with your provider.
- [ ] **Resumable local/SFTP uploads** – only S3 resumes within a file (multipart parts); local/SFTP restart the file (finished files are never repeated).
- [ ] **True incremental database backups** (binlog based) – database is exported completely on every run (as allowed by the spec).
- [ ] **Selective restore of single sites of a multisite network** (full network backup/restore works).
- [ ] **Content de-duplication of renamed files** in incremental backups (renamed files are stored again).
- [ ] **`.pot` / German translation files** for the PHP side (`languages/` is prepared, textdomain `swbackupit`; the SPA ships its own de/en catalogue).
- [ ] **Docblocks for all adapter methods** (documented WPCS relaxation in `phpcs-wordpress.xml.dist`; typed signatures carry the contract).
- [ ] SSE for live progress (REST polling is implemented; SSE was optional).
- [ ] Database part download count in the backup resource (`database_part_count`) so the UI can offer all parts.
- [ ] Upload parallelism setting (`max_parallel_uploads` is stored but uploads are sequential).

## Ideas
- [ ] Per-site backups on multisite; WebDAV/FTP adapters as optional Composer packages; restore test scheduling UI polish.
- [ ] Hosting matrix tests (PHP-FPM worker kills, very low `memory_limit`, restrictive open_basedir).
