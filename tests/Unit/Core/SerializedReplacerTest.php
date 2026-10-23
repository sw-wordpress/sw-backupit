<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Restore\Migration\SerializedReplacer;

final class SerializedReplacerTest extends TestCase
{
    private const PAIRS = ['https://alte-domain.de' => 'https://neue-domain.de/wordpress'];

    public function testPlainTextAndJson(): void
    {
        $r = new SerializedReplacer(self::PAIRS);
        self::assertSame('Visit https://neue-domain.de/wordpress/x', $r->replace('Visit https://alte-domain.de/x'));
        self::assertSame('{"url":"https:\/\/neue-domain.de\/wordpress\/a","n":1}', $r->replace('{"url":"https:\/\/alte-domain.de\/a","n":1}'));
        self::assertSame('nothing to do', $r->replace('nothing to do'));
        self::assertSame(2, $r->replacements);
    }

    public function testSerializedLengthsAreRewritten(): void
    {
        $original = serialize(['url' => 'https://alte-domain.de/page', 'n' => 3, 'nested' => ['a' => 'https://alte-domain.de', 'b' => true, 'c' => null, 'd' => 1.5], 'ü' => 'ö https://alte-domain.de/ü']);
        $expected = serialize(['url' => 'https://neue-domain.de/wordpress/page', 'n' => 3, 'nested' => ['a' => 'https://neue-domain.de/wordpress', 'b' => true, 'c' => null, 'd' => 1.5], 'ü' => 'ö https://neue-domain.de/wordpress/ü']);
        $result = (new SerializedReplacer(self::PAIRS))->replace($original);
        self::assertSame($expected, $result);
        self::assertSame(unserialize($expected), unserialize($result), 'result is valid serialized data');
    }

    public function testSerializedInsideSerializedAndInsideJson(): void
    {
        $inner = serialize(['link' => 'https://alte-domain.de/inner']);
        $outer = serialize(['payload' => $inner, 'plain' => 'https://alte-domain.de']);
        $result = (new SerializedReplacer(self::PAIRS))->replace($outer);
        $decoded = unserialize($result);
        self::assertSame('https://neue-domain.de/wordpress', $decoded['plain']);
        self::assertSame(['link' => 'https://neue-domain.de/wordpress/inner'], unserialize($decoded['payload']));
    }

    public function testObjectsArePreservedWithoutInstantiation(): void
    {
        $evil = 'O:9:"EvilClass":2:{s:3:"url";s:22:"https://alte-domain.de";s:' . strlen("\0*\0hidden") . ':"' . "\0*\0hidden" . '";s:4:"keep";}';
        self::assertFalse(class_exists('EvilClass'));
        $result = (new SerializedReplacer(self::PAIRS))->replace($evil);
        self::assertStringContainsString('s:32:"https://neue-domain.de/wordpress";', $result);
        self::assertStringContainsString("\0*\0hidden", $result);
        self::assertStringStartsWith('O:9:"EvilClass":2:{', $result);
        self::assertFalse(class_exists('EvilClass'), 'nothing was instantiated or autoloaded');
    }

    public function testMalformedSerializedDataIsLeftUntouched(): void
    {
        $r = new SerializedReplacer(self::PAIRS);
        // Wrong declared length (corrupt in the database already).
        $broken = 's:5:"https://alte-domain.de";';
        self::assertSame($broken, $r->replace($broken));
        $truncated = 'a:2:{s:1:"a";s:22:"https://alte-domain.de";';
        self::assertSame($truncated, $r->replace($truncated));
        self::assertSame(2, $r->skipped);
        self::assertSame(0, $r->replacements);
    }

    public function testSpecialSerializedValuesAndReferences(): void
    {
        $object = new \stdClass();
        $object->self = 'https://alte-domain.de';
        $data = serialize([$object, $object, 'x' => -5, 'f' => 0.1, 'bin' => "a\0b"]);
        $result = (new SerializedReplacer(self::PAIRS))->replace($data);
        $decoded = unserialize($result);
        self::assertSame('https://neue-domain.de/wordpress', $decoded[0]->self);
        self::assertSame($decoded[0], $decoded[1], 'object references survive');
        self::assertSame("a\0b", $decoded['bin']);
    }

    public function testPlainTextThatMerelyStartsWithSerializedLookingPrefixIsNotMangled(): void
    {
        $r = new SerializedReplacer(self::PAIRS);
        self::assertSame('a: https://neue-domain.de/wordpress', $r->replace('a: https://alte-domain.de'));
        self::assertSame('s:text https://neue-domain.de/wordpress', $r->replace('s:text https://alte-domain.de'));
    }
}
