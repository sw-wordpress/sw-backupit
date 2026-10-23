#!/usr/bin/env bash
# Builds the self-contained standalone restore application (no Composer/WordPress needed on the target).
# Usage: bin/build-restore.sh <output-dir>
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:?output directory required}"
mkdir -p "$OUT/src/Core" "$OUT/src/Restore" "$OUT/vendor"
cp -r "$ROOT/packages/core/src/." "$OUT/src/Core/"
cp -r "$ROOT/packages/restore/src/." "$OUT/src/Restore/"
mkdir -p "$OUT/bin" && cp "$ROOT/packages/restore/bin/swbackupit-restore" "$OUT/bin/"
[ -d "$ROOT/packages/restore/public" ] && cp -r "$ROOT/packages/restore/public/." "$OUT/" || true

# Only runtime libraries the restore needs: psr/log (and a tiny PSR-4 autoloader, no Composer).
mkdir -p "$OUT/vendor/psr-log"
if [ -d "$ROOT/vendor/psr/log/src" ]; then cp -r "$ROOT/vendor/psr/log/src/." "$OUT/vendor/psr-log/"; fi
cat > "$OUT/vendor/autoload.php" <<'PHP'
<?php

declare(strict_types=1);

// Minimal PSR-4 autoloader for the standalone restore distribution.
spl_autoload_register(static function (string $class): void {
    static $map = [
        'SWBackupIt\\Core\\' => __DIR__ . '/../src/Core/',
        'SWBackupIt\\Restore\\' => __DIR__ . '/../src/Restore/',
        'Psr\\Log\\' => __DIR__ . '/psr-log/',
    ];
    foreach ($map as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
PHP
echo "restore distribution: $OUT"
