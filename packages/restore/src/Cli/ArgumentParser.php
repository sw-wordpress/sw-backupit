<?php

declare(strict_types=1);

namespace SWBackupIt\Restore\Cli;

/** Minimal argv parser: `command arg --flag --key=value --key value`. */
final class ArgumentParser
{
    /** @var list<string> */
    public array $positional = [];
    /** @var array<string, string|bool|list<string>> */
    public array $options = [];

    /**
     * @param list<string> $argv Without the script name.
     * @param list<string> $valueOptions Options that take a value (so "--key value" works).
     * @param list<string> $listOptions Options that may be repeated.
     */
    public function __construct(array $argv, array $valueOptions = [], array $listOptions = [])
    {
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];
            if (!str_starts_with($arg, '--')) {
                $this->positional[] = $arg;
                continue;
            }
            $name = substr($arg, 2);
            $value = true;
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            } elseif (in_array($name, $valueOptions, true) && isset($argv[$i + 1])) {
                $value = $argv[++$i];
            }
            if (in_array($name, $listOptions, true) && is_string($value)) {
                $existing = $this->options[$name] ?? [];
                $this->options[$name] = [...(is_array($existing) ? $existing : []), $value];
            } else {
                $this->options[$name] = $value;
            }
        }
    }

    public function string(string $name, string $default = ''): string
    {
        $value = $this->options[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) === true || ($this->options[$name] ?? '') === '1';
    }

    /**
     * @return list<string>
     */
    public function list(string $name): array
    {
        $value = $this->options[$name] ?? [];

        return is_array($value) ? $value : (is_string($value) ? [$value] : []);
    }
}
