<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Log;

/** Removes secrets from log contexts before they are persisted (CODE-STYLE §6.4). */
final class Redactor
{
    private const SENSITIVE = '/pass(word|phrase)?|secret|token|private_?key|access_?key|credential|authorization|api_?key|salt|auth_key/i';

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE, $key) === 1) {
                $result[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $result[$key] = self::redact($value);
            } elseif ($value instanceof \Throwable) {
                $result[$key] = $value::class;
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = is_string($value) ? self::redactString($value) : $value;
            } else {
                $result[$key] = get_debug_type($value);
            }
        }

        return $result;
    }

    public static function redactString(string $value): string
    {
        // URLs with embedded credentials: scheme://user:pass@host
        $value = preg_replace('#(://[^/\s:@]+):[^@/\s]+@#', '$1:[redacted]@', $value) ?? $value;

        return mb_substr($value, 0, 2000);
    }
}
