#!/usr/bin/env bash
# Verifies a built release ZIP exactly like the release workflow does. Usage: bin/verify-zip.sh <zip> [expected-zip-name]
# Optional env KEY_ID: the key ring shipped in the ZIP must contain this key ID.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ZIP="${1:?usage: verify-zip.sh <zip> [expected-name]}"
SLUG=sw-backupit
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

[ -z "${2:-}" ] || [ "$(basename "$ZIP")" = "$2" ] || { echo "Unexpected ZIP name" >&2; exit 1; }
unzip -t "$ZIP" >/dev/null
unzip -Z1 "$ZIP" > "$TMP/zip-contents.txt"
for path in sw-backupit.php vendor-prefixed/autoload.php restore-app/vendor/autoload.php assets/admin/app.js assets/js/swbackupit-updates.js packages/wordpress/src/Update/Service.php packages/wordpress/src/Update/Config/trusted-keys.php; do
  grep -Fx "$SLUG/$path" "$TMP/zip-contents.txt" >/dev/null || { echo "Missing $SLUG/$path" >&2; exit 1; }
done
if grep -Eq "^$SLUG/(vendor|tests|apps|bin|node_modules|\.git|\.github)/" "$TMP/zip-contents.txt"; then
  echo "Unexpected development or unprefixed vendor files in ZIP" >&2
  exit 1
fi
if [ -n "${KEY_ID:-}" ]; then
  unzip -p "$ZIP" "$SLUG/packages/wordpress/src/Update/Config/trusted-keys.php" | grep -F "$KEY_ID" >/dev/null || { echo "Key ring lacks $KEY_ID" >&2; exit 1; }
fi
unzip -q "$ZIP" -d "$TMP/unpacked"
php "$ROOT/tests/bootstrap.php" "$TMP/unpacked/$SLUG"
php "$TMP/unpacked/$SLUG/restore-app/bin/sw-backupit-restore" help >/dev/null
echo "OK: $ZIP"
