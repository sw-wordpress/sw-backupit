<?php

declare(strict_types=1);

/**
 * Build-only workaround for Mozart 1.2.3's recursive package loading (Flysystem cycle).
 * Runs inside the pinned Mozart container. Remove when upstream handles dependency cycles.
 */

require '/mozart/vendor/autoload.php';

$source = file_get_contents('/mozart/src/PackageFinder.php');
if ($source === false) {
    throw new RuntimeException('Mozart PackageFinder source is missing.');
}
$replacements = [
    'private ?Mozart $config = null;' => 'private ?Mozart $config = null; private array $packageCache = []; private array $loading = [];',
    'public function getPackageBySlug(string $slug): ?Package' . "\n    {"
        => 'public function getPackageBySlug(string $slug): ?Package' . "\n    {\n"
            . '        if (isset($this->loading[$slug])) { return null; }'
            . '        if (isset($this->packageCache[$slug])) { return $this->packageCache[$slug]; }',
    '$package->loadDependencies($this);' => '$this->loading[$slug] = true;'
        . ' try { $package->loadDependencies($this); } finally { unset($this->loading[$slug]); }'
        . ' $this->packageCache[$slug] = $package;',
];
foreach ($replacements as $search => $replacement) {
    if (substr_count($source, $search) !== 1) {
        throw new RuntimeException('Mozart PackageFinder changed; review the cycle workaround before building.');
    }
    $source = str_replace($search, $replacement, $source);
}

// Load the narrowly patched class before Mozart's normal autoloader resolves it.
$patched = tempnam(sys_get_temp_dir(), 'swbackupit-mozart-');
if ($patched === false || file_put_contents($patched, $source) === false) {
    throw new RuntimeException('Cannot create the temporary Mozart cycle workaround.');
}
try {
    require $patched;
} finally {
    unlink($patched);
}
require '/mozart/bin/mozart';
