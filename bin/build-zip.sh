#!/usr/bin/env bash
# Builds an installable WordPress plugin ZIP: production Composer dependencies + compiled admin bundle.
# Usage: bin/build-zip.sh [output-dir]   (needs composer and node/npm on the BUILD machine only)
# The admin bundle is always rebuilt from the current sources so a release never ships stale UI assets;
# SWBI_SKIP_ADMIN_BUILD=1 reuses assets/admin, but only when it is newer than every file in apps/admin/src.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
NAME=swbackupit

if [ "${SWBI_SKIP_ADMIN_BUILD:-0}" = "1" ] && [ -f "$ROOT/assets/admin/app.js" ] \
  && [ -z "$(find "$ROOT/apps/admin/src" "$ROOT/apps/admin/package-lock.json" -newer "$ROOT/assets/admin/app.js" -print -quit)" ]; then
  echo ">> reusing admin bundle (SWBI_SKIP_ADMIN_BUILD=1, assets are up to date)"
else
  echo ">> building admin bundle"
  (cd "$ROOT/apps/admin" && npm ci && npm run build)
fi

echo ">> assembling plugin tree"
mkdir -p "$STAGE/$NAME"
# Untracked, not ignored files (local notes, audit reports, experiments) are never shipped.
UNTRACKED="$STAGE/untracked.txt"
if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  git -C "$ROOT" ls-files --others --exclude-standard | sed 's|^|/|' > "$UNTRACKED"
else
  : > "$UNTRACKED"
fi
rsync -a --exclude-from="$UNTRACKED" --exclude-from=- "$ROOT/" "$STAGE/$NAME/" <<'EXCLUDES'
.git
.gitignore
.editorconfig
.phpunit.cache
.phpstan.cache
.phpcs*.cache
/vendor
/node_modules
/apps
/tests
/dist
/build
/bin/test-db.sh
/docs/implementation-plan.md
phpunit.xml.dist
phpstan.neon.dist
phpcs*.xml.dist
PLAN.md
CODE-STYLE.md
TODO.md
DONE.md
AGENTS.md
composer.lock
EXCLUDES

echo ">> installing production dependencies"
cp "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/$NAME/"
(cd "$STAGE/$NAME" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)
rm -f "$STAGE/$NAME/composer.lock"

echo ">> building the standalone restore distribution"
"$ROOT/bin/build-restore.sh" "$STAGE/$NAME/restore-app" >/dev/null

mkdir -p "$OUT"
VERSION="$(grep -m1 "Version:" "$ROOT/swbackupit.php" | awk '{print $NF}')"
# zip -r would update an existing archive and keep files that no longer exist: always start from scratch.
rm -f "$OUT/$NAME-$VERSION.zip"
(cd "$STAGE" && zip -qr "$OUT/$NAME-$VERSION.zip" "$NAME")
echo ">> $OUT/$NAME-$VERSION.zip"
