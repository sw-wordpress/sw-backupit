<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Support\AtomicFile;

/** Creates the configuration of the web wizard (token hash, expiry, backups directory). The token itself is never stored. */
final class WebSetup
{
    /**
     * @param list<string> $targetRoots Optional allow list for restore targets.
     * @return string The one-time token (show it to the operator once).
     */
    public static function initialize(string $appDirectory, string $backupsDirectory, int $ttlSeconds = 3600, ?string $stateDirectory = null, array $targetRoots = [], string $suggestedTarget = '', string $language = ''): string
    {
        $backups = realpath($backupsDirectory);
        if ($backups === false || !is_dir($backups)) {
            throw new ConfigurationException('The backups directory does not exist.');
        }
        if (!is_dir($appDirectory)) {
            throw new ConfigurationException('The restore application directory does not exist.');
        }
        if ($ttlSeconds < 60 || $ttlSeconds > 7 * 86400) {
            throw new ConfigurationException('The token lifetime must be between 1 minute and 7 days.');
        }
        $token = bin2hex(random_bytes(24));
        $config = [
            'token_hash' => hash('sha256', $token),
            'expires_at' => time() + $ttlSeconds,
            'backups_dir' => $backups,
            'state_dir' => $stateDirectory ?? dirname(rtrim($appDirectory, '/')) . '/.swbi-restore-state-' . substr(hash('sha256', $appDirectory), 0, 10),
            'allowed_target_roots' => $targetRoots,
            'suggested_target' => $suggestedTarget,
            'language' => $language,
        ];
        $php = "<?php\n// Generated. Contains only a token hash, never the token.\nreturn " . var_export($config, true) . ";\n";
        AtomicFile::write(rtrim($appDirectory, '/') . '/config.php', $php);
        @chmod(rtrim($appDirectory, '/') . '/config.php', 0600);
        // A fresh token revokes older sessions and a previous lock.
        $state = $config['state_dir'];
        AtomicFile::removeTree($state . '/sessions');
        @unlink($state . '/locked');
        @unlink($state . '/ratelimit.json');

        return $token;
    }
}
