<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

use SWBackupIt\Core\Exception\ConfigurationException;
use SWBackupIt\Core\Support\AtomicFile;
use SWBackupIt\Core\Support\PathGuard;

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
            'state_dir' => $stateDirectory ?? self::createStateDirectory(),
            'allowed_target_roots' => $targetRoots,
            'suggested_target' => $suggestedTarget,
            'language' => $language,
        ];
        self::protectStateDirectory($config['state_dir']);
        $php = "<?php\n// Generated. Contains only a token hash, never the token.\nreturn " . var_export($config, true) . ";\n";
        AtomicFile::write(rtrim($appDirectory, '/') . '/config.php', $php);
        @chmod(rtrim($appDirectory, '/') . '/config.php', 0600);
        // A fresh token revokes older sessions and a previous lock.
        $state = $config['state_dir'];
        AtomicFile::removeTree(PathGuard::resolveBelow($state, 'sessions'));
        @unlink(PathGuard::resolveBelow($state, 'locked'));
        @unlink(PathGuard::resolveBelow($state, 'ratelimit.json'));
        @unlink(PathGuard::resolveBelow($state, 'token-used'));

        return $token;
    }

    /** Allocate unpredictable state exclusively; never adopt a pre-created default directory. */
    public static function createStateDirectory(): string
    {
        $directory = PathGuard::resolveBelow(sys_get_temp_dir(), '.swbi-restore-state-' . bin2hex(random_bytes(16)));
        // A competing creation must fail closed, without accepting the existing directory.
        if (!@mkdir($directory, 0700)) {
            throw new ConfigurationException('The restore state directory is not private or owned by the current user.');
        }

        return $directory;
    }

    /**
     * Protect existing state before any session is read. Reject foreign-owned, writable or symlinked directories.
     * POSIX ownership and mode checks use the effective process owner, independently of source-file ownership.
     *
     * @throws ConfigurationException When private state cannot be established and verified.
     */
    public static function protectStateDirectory(string $directory): void
    {
        self::ensurePrivateDirectory($directory);
        self::ensurePrivateDirectory(PathGuard::resolveBelow($directory, 'sessions'));
        AtomicFile::write(PathGuard::resolveBelow($directory, '.htaccess'), "Require all denied\nDeny from all\n");
        AtomicFile::write(PathGuard::resolveBelow($directory, 'web.config'), '<?xml version="1.0" encoding="UTF-8"?>' .
            '<configuration><system.webServer><security><authorization>' .
            '<clear/><add accessType="Deny" users="*"/>' .
            '</authorization></security></system.webServer></configuration>');
    }

    private static function ensurePrivateDirectory(string $directory): void
    {
        $directory = PathGuard::resolveBelow(dirname($directory), basename($directory));
        if (!file_exists($directory) && !@mkdir($directory, 0700)) {
            throw new ConfigurationException('The restore state directory is not private or owned by the current user.');
        }
        clearstatcache(true, $directory);
        $stat = lstat($directory);
        if (
            $stat === false || ($stat['mode'] & 0170000) !== 0040000
            || (PHP_OS_FAMILY !== 'Windows' && ($stat['uid'] !== self::processOwner() || ($stat['mode'] & 0022) !== 0))
        ) {
            throw new ConfigurationException('The restore state directory is not private or owned by the current user.');
        }
        if (!chmod($directory, 0700)) {
            throw new ConfigurationException('The restore state directory is not private or owned by the current user.');
        }
        clearstatcache(true, $directory);
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($directory) & 0777) !== 0700) {
            throw new ConfigurationException('The restore state directory is not private or owned by the current user.');
        }
    }

    /** fstat on an open, newly created file reports the process owner even without the optional POSIX extension. */
    private static function processOwner(): int
    {
        $probe = tmpfile();
        if ($probe === false) {
            throw new ConfigurationException('The restore state directory is not private or owned by the current user.');
        }
        try {
            $stat = fstat($probe);
            if ($stat === false) {
                throw new ConfigurationException('The restore state directory is not private or owned by the current user.');
            }

            return $stat['uid'];
        } finally {
            fclose($probe);
        }
    }
}
