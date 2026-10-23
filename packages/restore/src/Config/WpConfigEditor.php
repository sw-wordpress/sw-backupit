<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Config;

use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Support\AtomicFile;

/**
 * Adjusts wp-config.php by *parsing* it as text (never by including/executing it).
 * Only whitelisted constants and `$table_prefix` are touched; everything else stays byte-identical.
 */
final class WpConfigEditor
{
    private const CONSTANTS = ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE', 'WP_HOME', 'WP_SITEURL'];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, string> $constants Whitelisted constant => value.
     * @return list<string> Names of constants that were changed.
     */
    public function update(array $constants, ?string $tablePrefix = null): array
    {
        $contents = is_file($this->path) ? file_get_contents($this->path) : false;
        if ($contents === false) {
            throw new ConfigurationException('wp-config.php was not found in the restore target.');
        }
        $changed = [];
        foreach ($constants as $name => $value) {
            if (!in_array($name, self::CONSTANTS, true)) {
                throw new ConfigurationException('Constant is not allowed to be changed.', ['constant' => $name]);
            }
            $pattern = '/(define\s*\(\s*([\'"])' . preg_quote($name, '/') . '\2\s*,\s*)(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(\s*\))/';
            $replacement = var_export($value, true);
            $count = 0;
            $contents = preg_replace_callback($pattern, static fn (array $m): string => $m[1] . $replacement . $m[4], $contents, 1, $count) ?? $contents;
            if ($count === 1) {
                $changed[] = $name;
            }
        }
        if ($tablePrefix !== null) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $tablePrefix) !== 1) {
                throw new ConfigurationException('Invalid table prefix.');
            }
            $count = 0;
            $contents = preg_replace_callback(
                '/(\$table_prefix\s*=\s*)(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(\s*;)/',
                static fn (array $m): string => $m[1] . var_export($tablePrefix, true) . $m[3],
                $contents,
                1,
                $count,
            ) ?? $contents;
            if ($count === 1) {
                $changed[] = 'table_prefix';
            }
        }
        if ($changed !== []) {
            AtomicFile::write($this->path, $contents);
        }

        return $changed;
    }

    /**
     * Reads the table prefix declared in wp-config.php without executing it.
     */
    public function tablePrefix(): ?string
    {
        $contents = is_file($this->path) ? file_get_contents($this->path) : false;
        if ($contents !== false && preg_match('/\$table_prefix\s*=\s*([\'"])([A-Za-z0-9_]+)\1\s*;/', $contents, $match) === 1) {
            return $match[2];
        }

        return null;
    }
}
