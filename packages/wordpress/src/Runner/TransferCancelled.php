<?php
/**
 * Signals that the user cancelled a running transfer.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Runner;

final class TransferCancelled extends \RuntimeException {
}
