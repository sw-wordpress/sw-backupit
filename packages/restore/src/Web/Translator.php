<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Web;

/**
 * Minimal translator of the standalone restore wizard (no gettext, no WordPress). The catalogs in `lang/<language>.php` are generated
 * from the plugin's .po files by bin/i18n.sh (only the entries that belong to the restore application). English is the source language;
 * unknown strings are shown in English.
 */
final class Translator
{
    public const LANGUAGES = ['en', 'de', 'fr', 'pl', 'it'];

    private static string $language = 'en';
    /** @var array<string, string> */
    private static array $catalog = [];

    /** Selects the language: explicit configuration first, then the browser's Accept-Language header. */
    public static function select(string $configured, string $acceptLanguage = ''): void
    {
        $language = in_array($configured, self::LANGUAGES, true) ? $configured : self::fromHeader($acceptLanguage);
        self::$language = $language;
        // Monorepo: packages/restore/lang; standalone distribution: <app>/lang.
        $file = dirname(__DIR__, 2) . '/lang/' . $language . '.php';
        if (!is_file($file)) {
            $file = dirname(__DIR__, 3) . '/lang/' . $language . '.php';
        }
        $catalog = $language !== 'en' && is_file($file) ? require $file : [];
        self::$catalog = is_array($catalog) ? $catalog : [];
    }

    public static function language(): string
    {
        return self::$language;
    }

    /**
     * @param string|int ...$arguments sprintf arguments
     */
    public static function t(string $message, string|int ...$arguments): string
    {
        $translated = self::$catalog[$message] ?? $message;

        return $arguments === [] ? $translated : vsprintf($translated, $arguments);
    }

    private static function fromHeader(string $header): string
    {
        foreach (explode(',', strtolower($header)) as $part) {
            $code = substr(trim(explode(';', $part)[0]), 0, 2);
            if (in_array($code, self::LANGUAGES, true)) {
                return $code;
            }
        }

        return 'en';
    }
}
