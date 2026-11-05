# SonarQube findings review (2026-11-05)

Project: `sw-wordpress_sw-backupit`, default branch, 16 open findings retrieved through MCP.
Issue keys below share the prefix `AaF-K`.

| Issue suffixes | Assessment and action |
| --- | --- |
| `pnmJD85pmDQAYln`, `pnmJD85pmDQAYlo` | Downloader path findings are valid at the public core boundary: IDs were concatenated before validation, including ancestor IDs from manifests. Validate UUIDs first and resolve every local data/manifest path below the configured root, rejecting symlinks. Publish the originally verified manifest atomically instead of downloading it again. |
| `pnxJD85pmDQAYls`, `pnxJD85pmDQAYlr` | REST import already filters IDs through `Uuid::isValid`, so request-body `../` traversal is not reproducible here. Existing symlinks still bypassed root confinement. Guard the destination and scratch directory; replace unchecked mkdir/write calls with the core atomic writer. UUID validation now rejects trailing newlines too. |
| `pnTJD85pmDQAYll` | `AtomicFile::readJson` is a general-purpose reader and cannot impose a root itself. Fix its untrusted callers: catalog reads validate IDs and use independent random scratch names; the restore wizard rejects symlink backup directories and manifests, including dashboard discovery. |
| `pmxJD85pmDQAYlY`, `pm1JD85pmDQAYlZ` | Both regex performance findings are valid. Email delimiter searches and a backwards trailing-slash scan replace ambiguous backtracking. Tests include 100,000-character inputs. |
| `plXJD85pmDQAYlW`, `pm3JD85pmDQAYla` | Vue/Quasar label association is opaque to the HTML analyzer. Use the checkbox's own label slot for the entire card, preserving accessible names, whole-card selection, keyboard focus and disabled behavior. Component tests exercise the rendered controls. |
| `pnMJD85pmDQAYlj`, `pnMJD85pmDQAYlk` | False positives: scratch CREATE/DROP DATABASE statements use an internally generated random name, quoted by `DatabaseExporter::id`, which doubles embedded backticks. Keep these DDL statements; placeholders cannot stand for SQL identifiers. |
| `pnhJD85pmDQAYlm` | False positive: `ConnectionConfig` validates the charset against an alphanumeric/underscore allowlist before `SET NAMES` interpolates it. No SQL syntax can enter through this value. |
| `pnvJD85pmDQAYlp`, `pnvJD85pmDQAYlq` | False positives: MD5 only indexes notification deduplication timestamps. These are not passwords, authentication tokens, signatures or integrity checks. Retain existing keys so upgrades preserve the active deduplication window. |
| `pnJJD85pmDQAYli` | False positive: constructing `ConnectionConfig` deliberately validates submitted database settings and throws on invalid input before storing the restore plan. Removing it would remove validation. |
| `pmwJD85pmDQAYlX` | False positive: `tabindex="0"` makes the overflowing JSON preview keyboard-scrollable. Keep it and its accessible name; removing focus would regress keyboard access. |

Additional confirmed issue found during review: `RestoreTester::checkDatabase` interpolated the
manifest's table prefix into a quoted `SHOW TABLES LIKE` pattern, escaping wildcards but not quotes.
Replace it with an exact `information_schema.TABLES` lookup using a bound value. The regression test
uses a quote/SQL payload and verifies that it cannot match another options table.

Path checks assume configured staging/state roots are private and owned by the application; PHP path
checks do not provide protection against a hostile local process concurrently replacing path components.

## Verification

- PHPUnit unit/integration, WordPress single-site and restore end-to-end suites; regression coverage for
  traversal, ancestor IDs, directory/file symlinks, scratch files, manifest replacement and SQL prefix input.
- Composer PSR-12/WPCS and PHPStan level 8; frontend TypeScript, ESLint, Prettier, Vitest and production build.
- Test counts: 150 unit/integration, 76 WordPress, 14 e2e, 90 frontend; 9 PHP tests skipped.
- Measured line coverage: PHP 81.07% (merged unit/integration, WordPress, e2e); frontend 93.63%, both >80%.
- S3-service and multisite tests skip in this local single-site setup. PHP 8.5 reports existing PDO and
  reflection deprecations; coding standards report existing non-fatal warnings.

These are local fixes and assessments. No SonarQube issue statuses were changed. A new Cloud analysis
after publishing the branch must confirm the fixed findings; the false-positive assessments above
remain available for review without suppressing rules in source.
