<?php

/**
 * Build-only workarounds for Mozart 1.2.3's recursive package loading (Flysystem cycle)
 * and missing dependency-reference rewriting in the root application's source directories.
 * Runs inside the pinned Mozart container. Remove when upstream handles these cases.
 */

declare(strict_types=1);

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

// Mozart rewrites dependencies inside vendor-prefixed, but never visits the root PSR-4 sources.
// Apply its AST replacer there too, using the same resolved dependency graph and symbol mappings.
$composeSource = file_get_contents('/mozart/src/Commands/Compose.php');
$search = '$parentReplacer->replaceParentInTree($packages);';
$replacement = $search . <<<'PHP'

        foreach (['packages/core/src', 'packages/restore/src', 'packages/wordpress/src'] as $directory) {
            $applicationDirectory = $config->getWorkingDir() . $directory;
            foreach ($packages as $dependency) {
                if ($config->isExcludedPackage($dependency)) {
                    continue;
                }
                foreach ($dependency->getAutoloaders() as $autoloader) {
                    if ($autoloader instanceof \CoenJacobs\Mozart\Composer\Autoload\NamespaceAutoloader) {
                        $replacer->replaceInDirectory($autoloader, $applicationDirectory);
                    }
                }
            }
            $parentReplacer->replaceParentClassesInDirectory($directory);
        }
PHP;
if ($composeSource === false || substr_count($composeSource, $search) !== 1) {
    throw new RuntimeException('Mozart Compose changed; review application dependency rewriting before building.');
}
$patchedCompose = tempnam(sys_get_temp_dir(), 'swbackupit-mozart-compose-');
if ($patchedCompose === false || file_put_contents($patchedCompose, str_replace($search, $replacement, $composeSource)) === false) {
    throw new RuntimeException('Cannot create the temporary Mozart application rewrite workaround.');
}
try {
    require $patchedCompose;
} finally {
    unlink($patchedCompose);
}
require '/mozart/bin/mozart';
