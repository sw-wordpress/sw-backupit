<?php

declare(strict_types=1);

/**
 * Entry point of the standalone web restore wizard.
 * The configuration (token hash, expiry, backups directory) is written by `swbackupit-restore web-init` or by the plugin.
 * Without a valid configuration the application does nothing.
 */

$configFile = __DIR__ . '/config.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($configFile) || !is_file($autoload)) {
    http_response_code(403);
    exit('The restore application is not initialised.');
}
require $autoload;
$config = require $configFile;
if (!is_array($config)) {
    http_response_code(500);
    exit('Invalid configuration.');
}
$config['app_dir'] = __DIR__;

\SWBackupIt\Restore\Web\WebRequest::fromGlobals();
(new \SWBackupIt\Restore\Web\WebApp($config))->handle(\SWBackupIt\Restore\Web\WebRequest::fromGlobals())->send();
