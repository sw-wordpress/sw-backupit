<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Sql;

use Generator;

/**
 * Incremental SQL statement splitter for dump files (mysqldump / sw-backupit dialect).
 *
 * Understands quoted strings (with backslash and doubled-quote escapes), backtick identifiers,
 * `-- `, `#` and block comments, and the `DELIMITER` client command used around triggers/routines.
 * State is kept between feed() calls so arbitrarily large statements are handled in a single pass.
 */
final class StatementSplitter
{
    private string $buffer = '';
    private int $scan = 0;
    private string $delimiter = ';';
    private ?string $mode = null;

    /**
     * @return Generator<int, string>
     */
    public function feed(string $data): Generator
    {
        $this->buffer .= $data;

        yield from $this->drain(false);
    }

    /**
     * @return Generator<int, string>
     */
    public function finish(): Generator
    {
        yield from $this->drain(true);
        $rest = trim($this->buffer);
        $this->buffer = '';
        $this->scan = 0;
        if ($rest !== '' && !$this->isCommentOnly($rest)) {
            yield $rest;
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function drain(bool $final): Generator
    {
        while (true) {
            if ($this->mode === null && $this->scan <= strspn($this->buffer, " \t\r\n")) {
                $command = $this->takeDelimiterCommand($final);
                if ($command === 'wait') {
                    return;
                }
                if ($command === 'consumed') {
                    continue;
                }
            }
            $length = strlen($this->buffer);
            if ($this->scan >= $length) {
                return;
            }
            $progressed = match ($this->mode) {
                null => yield from $this->scanNormal($final),
                "'", '"', '`' => $this->scanQuoted($final),
                '--', '#' => $this->scanLineComment(),
                '/*' => $this->scanBlockComment(),
                default => false,
            };
            if ($progressed === false) {
                return;
            }
        }
    }

    /**
     * @return Generator<int, string, mixed, bool> true = keep going, false = need more data.
     */
    private function scanNormal(bool $final): Generator
    {
        $delimiterStart = preg_quote($this->delimiter[0], '/');
        $pattern = '/[\'"`#\/\-' . $delimiterStart . ']/';
        if (preg_match($pattern, $this->buffer, $match, PREG_OFFSET_CAPTURE, $this->scan) !== 1) {
            $this->scan = max($this->scan, strlen($this->buffer) - strlen($this->delimiter) + 1);

            return false;
        }
        $char = $match[0][0];
        $position = $match[0][1];
        $length = strlen($this->buffer);

        if ($char === $this->delimiter[0]) {
            $end = $position + strlen($this->delimiter);
            if ($end > $length) {
                if (!$final) {
                    $this->scan = $position;

                    return false;
                }
                $this->scan = $length;

                return true;
            }
            if (substr($this->buffer, $position, strlen($this->delimiter)) === $this->delimiter) {
                $statement = trim(substr($this->buffer, 0, $position));
                $this->buffer = substr($this->buffer, $end);
                $this->scan = 0;
                if ($statement !== '' && !$this->isCommentOnly($statement)) {
                    yield $statement;
                }

                return true;
            }
            $this->scan = $position + 1;

            return true;
        }
        if ($char === '\'' || $char === '"' || $char === '`') {
            $this->mode = $char;
            $this->scan = $position + 1;

            return true;
        }
        if ($char === '#') {
            $this->mode = '#';
            $this->scan = $position + 1;

            return true;
        }
        if ($position + 1 >= $length && !$final) {
            $this->scan = $position;

            return false;
        }
        $next = $this->buffer[$position + 1] ?? '';
        if ($char === '-' && $next === '-') {
            $third = $this->buffer[$position + 2] ?? '';
            if ($position + 2 >= $length && !$final) {
                $this->scan = $position;

                return false;
            }
            if ($third === '' || ctype_space($third) || ctype_cntrl($third)) {
                $this->mode = '--';
                $this->scan = $position + 2;

                return true;
            }
        } elseif ($char === '/' && $next === '*') {
            $this->mode = '/*';
            $this->scan = $position + 2;

            return true;
        }
        $this->scan = $position + 1;

        return true;
    }

    private function scanQuoted(bool $final): bool
    {
        $quote = (string) $this->mode;
        $pattern = $quote === '`' ? '/`/' : '/[\\\\' . $quote . ']/';
        $length = strlen($this->buffer);
        while (preg_match($pattern, $this->buffer, $match, PREG_OFFSET_CAPTURE, $this->scan) === 1) {
            $position = $match[0][1];
            if ($match[0][0] === '\\') {
                if ($position + 1 >= $length) {
                    $this->scan = $position;

                    return $final;
                }
                $this->scan = $position + 2;

                continue;
            }
            if ($position + 1 >= $length && !$final) {
                $this->scan = $position;

                return false;
            }
            if (($this->buffer[$position + 1] ?? '') === $quote) {
                $this->scan = $position + 2;

                continue;
            }
            $this->mode = null;
            $this->scan = $position + 1;

            return true;
        }
        $this->scan = $length;

        return false;
    }

    private function scanLineComment(): bool
    {
        $position = strpos($this->buffer, "\n", $this->scan);
        if ($position === false) {
            $this->scan = strlen($this->buffer);

            return false;
        }
        $this->mode = null;
        $this->scan = $position + 1;

        return true;
    }

    private function scanBlockComment(): bool
    {
        $position = strpos($this->buffer, '*/', $this->scan);
        if ($position === false) {
            $this->scan = max($this->scan, strlen($this->buffer) - 1);

            return false;
        }
        $this->mode = null;
        $this->scan = $position + 2;

        return true;
    }

    /**
     * Handles a `DELIMITER x` line at the start of a statement.
     *
     * @return 'none'|'wait'|'consumed'
     */
    private function takeDelimiterCommand(bool $final): string
    {
        $skip = strspn($this->buffer, " \t\r\n");
        $head = strtoupper(substr($this->buffer, $skip, 10));
        if ($head === '') {
            return 'none';
        }
        if (strlen($head) < 10) {
            return !$final && str_starts_with('DELIMITER ', $head) ? 'wait' : 'none';
        }
        if ($head !== 'DELIMITER ' && $head !== "DELIMITER\t") {
            return 'none';
        }
        $newline = strpos($this->buffer, "\n", $skip);
        if ($newline === false && !$final) {
            return 'wait';
        }
        $line = $newline === false ? substr($this->buffer, $skip) : substr($this->buffer, $skip, $newline - $skip);
        $newDelimiter = trim(substr($line, 10));
        if ($newDelimiter !== '') {
            $this->delimiter = $newDelimiter;
        }
        $this->buffer = $newline === false ? '' : substr($this->buffer, $newline + 1);
        $this->scan = 0;

        return 'consumed';
    }

    private function isCommentOnly(string $statement): bool
    {
        if (strlen($statement) > 4096) {
            return false;
        }

        return preg_match('/^(?:\s+|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*(?!\!)(?:(?!\*\/).)*\*\/)*$/s', $statement) === 1;
    }
}
