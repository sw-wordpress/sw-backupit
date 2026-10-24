<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use SWBackupIt\Restore\Web\Translator;

/**
 * Guards the translation workflow: every string in the code is in the .pot, every shipped language translates all of them, and the
 * compiled catalogs are current. When this fails after changing user-facing strings run: bin/i18n.sh update (then translate the new entries).
 */
final class I18nTest extends TestCase
{
    public function testCatalogsAreCompleteAndUpToDate(): void
    {
        exec('which xgettext msgfmt msgmerge 2>/dev/null', $tools, $code);
        if ($code !== 0 || count($tools) < 3) {
            self::markTestSkipped('GNU gettext is not installed.');
        }
        exec(escapeshellarg(dirname(__DIR__, 3) . '/bin/i18n.sh') . ' check 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", array_slice($output, -5)));
    }

    public function testRestoreWizardTranslatesAndFallsBackToEnglish(): void
    {
        Translator::select('de');
        self::assertSame('Anmelden', Translator::t('Sign in'));
        self::assertSame('Unknown string stays English', Translator::t('Unknown string stays English'));
        self::assertStringContainsString('5 Minuten', Translator::t('Too many failed attempts. Try again in %d minutes.', 5));
        Translator::select('', 'fr-CH,fr;q=0.9,en;q=0.8');
        self::assertSame('fr', Translator::language());
        Translator::select('', 'ja,zh;q=0.9');
        self::assertSame('en', Translator::language());
        self::assertSame('Sign in', Translator::t('Sign in'));
        Translator::select('pl');
        self::assertNotSame('Sign in', Translator::t('Sign in'));
        Translator::select('it');
        self::assertNotSame('Sign in', Translator::t('Sign in'));
    }
}
