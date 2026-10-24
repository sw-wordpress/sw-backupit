<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Manifest\Manifest;

final class PluginRenameTest extends TestCase
{
    public function testCurrentAndLegacyBackupNamesRemainReadable(): void
    {
        self::assertSame('sw-backupit', Manifest::FORMAT);

        foreach (['sw-backupit', 'swbackupit'] as $format) {
            $manifest = Manifest::fromArray([
                'format' => $format,
                'format_version' => 1,
                'backup_id' => '11111111-1111-4111-8111-111111111111',
                'type' => 'files',
                'created_at' => '2026-10-24T00:00:00Z',
            ]);

            self::assertSame('11111111-1111-4111-8111-111111111111', $manifest->backupId());
        }
    }
}
