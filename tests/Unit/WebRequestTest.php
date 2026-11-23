<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Restore\Web\WebRequest;

final class WebRequestTest extends TestCase
{
    /** @param array<string, mixed> $server */
    #[DataProvider('httpsServerValues')]
    public function testDetectsHttps(array $server, bool $expected): void
    {
        self::assertSame($expected, (new WebRequest('GET', server: $server))->isHttps());
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function httpsServerValues(): iterable
    {
        yield 'missing' => [[], false];
        yield 'null' => [['HTTPS' => null], false];
        yield 'empty' => [['HTTPS' => ''], false];
        yield 'off' => [['HTTPS' => 'off'], false];
        yield 'on' => [['HTTPS' => 'on'], true];
        yield 'numeric flag' => [['HTTPS' => '1'], true];
    }
}
