#!/usr/bin/env bash
# Builds an installable WordPress plugin ZIP: production Composer dependencies + compiled admin bundle.
# Usage: bin/build-zip.sh [output-dir]   (needs composer and, unless assets/admin exists, node/npm on the BUILD machine only)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
NAME=swbackupit

if [ ! -f "$ROOT/assets/admin/app.js" ]; then
  echo ">> building admin bundle"
  (cd "$ROOT/apps/admin" && npm ci && npm run build)
fi

echo ">> assembling plugin tree"
mkdir -p "$STAGE/$NAME"
rsync -a --exclude-from=- "$ROOT/" "$STAGE/$NAME/" <<'EXCLUDES'
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
(cd "$STAGE" && zip -qr "$OUT/$NAME-$VERSION.zip" "$NAME")
echo ">> $OUT/$NAME-$VERSION.zip"
