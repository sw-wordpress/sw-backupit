<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Migration;

/**
 * Search/replace over database values that is safe for PHP serialized data (PLAN.md §13.3).
 *
 * PHP serialized strings carry their byte length (`s:12:"..."`), so a blind replace corrupts them. This class parses the
 * serialization format itself and rewrites lengths. It NEVER calls unserialize(): no objects are instantiated, no
 * __wakeup/__destruct code can run, regardless of what the backup contains.
 *
 * Handled: nested arrays/objects (including mangled private/protected property names), nested serialized strings
 * (serialized data inside serialized strings), JSON text (plain and slash-escaped URLs) and plain text.
 * A value that looks serialized but cannot be parsed completely is left UNTOUCHED and counted in $skipped.
 */
final class SerializedReplacer
{
    public int $replacements = 0;
    public int $skipped = 0;

    /** @var array<string, string> */
    private array $pairs;

    /**
     * @param array<string, string> $pairs search => replace (longest search strings are applied first)
     */
    public function __construct(array $pairs)
    {
        $pairs = array_filter($pairs, static fn (string $to, string $from): bool => $from !== '' && $from !== $to, ARRAY_FILTER_USE_BOTH);
        uksort($pairs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->pairs = $pairs;
    }

    public function replace(string $value): string
    {
        if ($this->pairs === [] || !$this->containsAny($value)) {
            return $value;
        }
        if ($this->looksSerialized($value)) {
            $position = 0;
            $replacementsBefore = $this->replacements;
            $skippedBefore = $this->skipped;
            $result = $this->parse($value, $position);
            if ($result !== null && $position === strlen($value)) {
                return $result;
            }
            // Looks serialized but is not parseable: never risk corrupting it (and drop partial counts).
            $this->replacements = $replacementsBefore;
            $this->skipped = $skippedBefore + 1;

            return $value;
        }

        return $this->replacePlain($value);
    }

    private function containsAny(string $value): bool
    {
        foreach (array_keys($this->pairs) as $search) {
            if (str_contains($value, $search) || str_contains($value, str_replace('/', '\\/', $search))) {
                return true;
            }
        }

        return false;
    }

    private function looksSerialized(string $value): bool
    {
        return preg_match('/^(?:[aOsibdNCEr]:\d|N;|a:\d+:\{|O:\d+:")/', $value) === 1 && preg_match('/^(?:a:\d+:\{|O:\d+:"|s:\d+:"|i:-?\d+;|b:[01];|d:[-+0-9.eE]+;|N;)/', $value) === 1;
    }

    /** Plain/JSON text: simple replacement, including JSON escaped slashes. */
    private function replacePlain(string $value): string
    {
        foreach ($this->pairs as $search => $replace) {
            $count = 0;
            $value = str_replace($search, $replace, $value, $count);
            $this->replacements += $count;
            $escapedSearch = str_replace('/', '\\/', $search);
            if ($escapedSearch !== $search) {
                $value = str_replace($escapedSearch, str_replace('/', '\\/', $replace), $value, $count);
                $this->replacements += $count;
            }
        }

        return $value;
    }

    /**
     * Recursive descent over the serialization grammar, returning the rewritten text or null on malformed input.
     */
    private function parse(string $data, int &$pos): ?string
    {
        if ($pos >= strlen($data)) {
            return null;
        }
        $type = $data[$pos];
        switch ($type) {
            case 'N':
                if (substr($data, $pos, 2) !== 'N;') {
                    return null;
                }
                $pos += 2;

                return 'N;';
            case 'b':
            case 'i':
            case 'd':
                if (preg_match('/\G([bid]):([-+0-9.eE]+|INF|-INF|NAN);/', $data, $m, 0, $pos) !== 1) {
                    return null;
                }
                $pos += strlen($m[0]);

                return $m[0];
            case 'r':
            case 'R':
                if (preg_match('/\G[rR]:\d+;/', $data, $m, 0, $pos) !== 1) {
                    return null;
                }
                $pos += strlen($m[0]);

                return $m[0];
            case 's':
                return $this->parseString($data, $pos, 's');
            case 'a':
                return $this->parseArray($data, $pos);
            case 'O':
                return $this->parseObject($data, $pos);
            default:
                return null; // C: (custom serialization) and E: (enums) are not rewritten
        }
    }

    private function parseString(string $data, int &$pos, string $prefix): ?string
    {
        if (preg_match('/\G' . $prefix . ':(\d+):"/', $data, $m, 0, $pos) !== 1) {
            return null;
        }
        $length = (int) $m[1];
        $start = $pos + strlen($m[0]);
        if ($start + $length + 2 > strlen($data) || substr($data, $start + $length, 2) !== '";') {
            return null;
        }
        $string = substr($data, $start, $length);
        $pos = $start + $length + 2;
        $replaced = $this->replace($string);

        return $prefix . ':' . strlen($replaced) . ':"' . $replaced . '";';
    }

    private function parseArray(string $data, int &$pos): ?string
    {
        if (preg_match('/\Ga:(\d+):\{/', $data, $m, 0, $pos) !== 1) {
            return null;
        }
        $count = (int) $m[1];
        $pos += strlen($m[0]);
        $out = 'a:' . $count . ':{';
        for ($i = 0; $i < $count * 2; $i++) {
            $part = $this->parse($data, $pos);
            if ($part === null) {
                return null;
            }
            $out .= $part;
        }
        if (($data[$pos] ?? '') !== '}') {
            return null;
        }
        $pos++;

        return $out . '}';
    }

    private function parseObject(string $data, int &$pos): ?string
    {
        if (preg_match('/\GO:(\d+):"/', $data, $m, 0, $pos) !== 1) {
            return null;
        }
        $nameLength = (int) $m[1];
        $nameStart = $pos + strlen($m[0]);
        if (substr($data, $nameStart + $nameLength, 2) !== '":') {
            return null;
        }
        $className = substr($data, $nameStart, $nameLength);
        $afterName = $nameStart + $nameLength + 2;
        if (preg_match('/\G(\d+):\{/', $data, $c, 0, $afterName) !== 1) {
            return null;
        }
        $count = (int) $c[1];
        $pos = $afterName + strlen($c[0]);
        $out = 'O:' . $nameLength . ':"' . $className . '":' . $count . ':{';
        for ($i = 0; $i < $count * 2; $i++) {
            // Property names are kept byte-identical (they contain NUL bytes for private/protected members).
            $part = $i % 2 === 0 ? $this->parseKey($data, $pos) : $this->parse($data, $pos);
            if ($part === null) {
                return null;
            }
            $out .= $part;
        }
        if (($data[$pos] ?? '') !== '}') {
            return null;
        }
        $pos++;

        return $out . '}';
    }

    private function parseKey(string $data, int &$pos): ?string
    {
        if (preg_match('/\Gs:(\d+):"/', $data, $m, 0, $pos) === 1) {
            $length = (int) $m[1];
            $start = $pos + strlen($m[0]);
            if (substr($data, $start + $length, 2) !== '";') {
                return null;
            }
            $key = substr($data, $start, $length);
            $pos = $start + $length + 2;

            return 's:' . $length . ':"' . $key . '";';
        }

        return $this->parse($data, $pos);
    }
}
