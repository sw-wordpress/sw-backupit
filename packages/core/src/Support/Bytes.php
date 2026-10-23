<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Support;

final class Bytes
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? $bytes . ' B' : sprintf('%.1f %s', $value, $units[$unit]);
    }
}
