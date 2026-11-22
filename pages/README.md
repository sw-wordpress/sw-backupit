# Documentation maintenance

## Page plan (recorded before implementation)

Audience: WordPress site/network administrators who create backups, manage off-site copies and recover or migrate a site. Existing README and user guide are English; public pages use English and the English UI labels.

| Page | Why it is needed / evidence |
| --- | --- |
| `index.md` | Factual feature overview and task links, not a second guide; README and existing user guide. |
| `getting-started.md` | Install the release ZIP, check staging and complete/verify the first backup; plugin header, DefaultProfiles, dashboard and profile UI. |
| `profiles-and-schedules.md` | Scope, incremental chains, retention, resource defaults and automatic runs are coupled operational decisions; profile defaults/wizard, schedule UI, existing user guide. |
| `storage-and-encryption.md` | Configure Local/SFTP/S3, understand success policy, protect credentials and preserve restore keys; storage/key UI, Settings, storage/crypto tests. |
| `backups-and-restore.md` | Browse, compare, transfer, download recovery packages, test restores and recover/migrate outside WordPress; backup/restore dialogs, BackupTransferTest, RestoreTesterTest and standalone CLI guide. |
| `settings-and-troubleshooting.md` | Operational defaults, cron failures, staging/privacy, update backups, notifications, logs and removal; Settings.php, jobs UI and existing security/user guides. |
| `Changelog.md` | Required user-facing change history. GitHub releases 1.0.0 and 0.8.1 have empty bodies, so do not invent historical feature notes. Link existing release history instead. |
| `404.md` | A short recovery link for missing pages; JavaScript renders the custom Layout footer, while a noscript-only fallback keeps the exact footer available without JavaScript. |

Technical API, format, CLI and developer material stays in `docs/`, linked rather than duplicated. No separate developer, FAQ or marketing pages. `README.md` is maintainer-only and excluded from the site/search.

## Build and update

VitePress is pinned to **1.6.4**, not added to the product dependencies. Node.js 22 and npm are sufficient for documentation. From the repository root:

```sh
DOCS_BUILD="$(mktemp -d "${TMPDIR:-/tmp}/sw-backupit-docs.XXXXXX")"
cp -R pages "$DOCS_BUILD/pages"
npm install --prefix "$DOCS_BUILD" --no-save --package-lock=false --ignore-scripts --no-audit --no-fund vitepress@1.6.4
"$DOCS_BUILD/node_modules/.bin/vitepress" build "$DOCS_BUILD/pages"
# Optional local preview; stop it before deleting the temporary directory.
"$DOCS_BUILD/node_modules/.bin/vitepress" preview "$DOCS_BUILD/pages"
# After preview: rm -rf "$DOCS_BUILD"
```

All dependencies, caches and output stay outside the checkout. No documentation package.json or lockfile is committed; transitive dependency versions are consequently not fully locked. Keep dead-link checking enabled. Add new pages to the sidebar, check UI wording against code and update `Changelog.md` in the same change. Use only real application screenshots with synthetic data under `pages/public/screenshots/`, meaningful alt text and no credentials or personal data. Screenshots are optional; do not create mocks or placeholder images.

The custom theme uses the always-present `layout-bottom` slot for the exact required footer, including sidebar and 404 pages. Do not also configure the default theme footer (it is hidden on sidebar pages and would duplicate the custom footer elsewhere).

VitePress 1.6.4 deliberately emits an empty `#app` in 404 HTML even with an explicit source. `transformHtml` adds a `noscript` recovery link/footer only for that route; JavaScript users receive only the Layout footer, never two visible footers.

### Screenshot provenance

`public/screenshots/sw-backupit-dashboard.png` and `sw-backupit-profiles.png` are genuine application captures from https://wp-demo.stefanwarnat.de/, supplied and visually checked by the parent agent. The WordPress identity/global bar was cropped. They show the initial demo state: no backups or storage targets and two sample profiles. They do not establish that a backup/restore has passed; displayed demo retention values are not asserted as defaults. Keep these sanitized assets and their descriptive alt text when updating the guide.

## GitHub Pages

Repository default branch: `main` (discovered before implementation). No custom-domain CNAME was found; base is `/sw-backupit/`. Expected site: https://sw-wordpress.github.io/sw-backupit/.

The prior Pages API request returned 404; that does not confirm a configured site or distinguish missing access. An authorized maintainer must confirm **Settings → Pages → Source → GitHub Actions** and the `github-pages` environment protection permits `main`. No repository settings are changed by this work.

`Documentation` checks relevant PRs and main-branch pushes, and accepts manual runs. A manual run on a non-main branch builds only. Only a successful main-branch build outside a pull request uploads the Pages artifact and permits deployment. Deployment alone receives Pages/OIDC write permissions and is serialized. No PAT or publishing branch is used. Build success is not evidence of publication: check the deployment result and served site after merge/setup.

## Packaging and verification

`bin/build-zip.sh` excludes `/pages`; both ZIP verifiers reject pages, documentation toolchain/cache output and development dependencies. Product dependencies and runtime behavior are unchanged. Check:

```sh
bash -n bin/build-zip.sh bin/verify-zip.sh
git diff --check
# With the existing PHP/Composer/Docker/admin build prerequisites:
make dist
```

The documentation workflow builds the copied source with VitePress's default dead-link checks. Review built HTML for navigation, `/sw-backupit/` asset URLs, local search, the exact footer once per page and the case-sensitive `Changelog.html` route. README must not become a public page. Preserve evidenced release notes; do not infer feature-to-version mappings from current functionality.
