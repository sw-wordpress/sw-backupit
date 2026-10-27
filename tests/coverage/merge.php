<?php

/** Merge native Clover reports, retaining the highest hit count for overlapping lines. */

declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/';
$files = [];
foreach (array_slice($argv, 1) as $report) {
    $xml = new DOMDocument();
    if (!$xml->load($report, LIBXML_NONET)) {
        throw new RuntimeException('Invalid Clover report: ' . $report);
    }
    foreach ($xml->getElementsByTagName('file') as $file) {
        $name = $file->getAttribute('name');
        $absolute = realpath($name);
        if ($absolute === false || !str_starts_with($absolute, $root)) {
            throw new RuntimeException('Coverage escaped project: ' . $name);
        }
        $name = substr($absolute, strlen($root));
        $files[$name] ??= [];
        foreach ($file->getElementsByTagName('line') as $line) {
            $attributes = [];
            foreach ($line->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }
            $key = $attributes['num'] . ':' . ($attributes['type'] ?? 'stmt');
            $previous = $files[$name][$key]['count'] ?? 0;
            $attributes['count'] = (string) max((int) $previous, (int) ($attributes['count'] ?? 0));
            $files[$name][$key] = $attributes;
        }
    }
}
if (!$files) {
    throw new RuntimeException('No production files in coverage reports.');
}
$document = new DOMDocument('1.0', 'UTF-8');
$document->formatOutput = true;
$coverage = $document->appendChild($document->createElement('coverage'));
$coverage->setAttribute('generated', (string) time());
$project = $coverage->appendChild($document->createElement('project'));
$project->setAttribute('timestamp', (string) time());
$executed = 0;
ksort($files);
foreach ($files as $name => $lines) {
    $file = $project->appendChild($document->createElement('file'));
    $file->setAttribute('name', $name);
    $statements = 0;
    $covered = 0;
    foreach ($lines as $attributes) {
        $line = $file->appendChild($document->createElement('line'));
        foreach ($attributes as $key => $value) {
            $line->setAttribute($key, $value);
        }
        if (($attributes['type'] ?? '') === 'stmt') {
            ++$statements;
            if ((int) $attributes['count'] > 0) {
                ++$covered;
                ++$executed;
            }
        }
    }
    $metrics = $file->appendChild($document->createElement('metrics'));
    $metrics->setAttribute('statements', (string) $statements);
    $metrics->setAttribute('coveredstatements', (string) $covered);
}
if ($executed === 0) {
    throw new RuntimeException('No executed production lines in coverage reports.');
}
if ($document->save($root . 'coverage/clover.xml') === false) {
    throw new RuntimeException('Could not write merged coverage.');
}
printf("Merged %d files, %d executed statement lines into coverage/clover.xml\n", count($files), $executed);
