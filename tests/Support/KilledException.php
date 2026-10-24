<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Support;

use RuntimeException;

/** Thrown by KillingPdo to simulate the process dying. */
final class KilledException extends RuntimeException
{
}
