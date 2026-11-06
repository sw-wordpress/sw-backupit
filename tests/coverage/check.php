<?php

/** Strict line coverage gate for the merged Clover report: coverage must exceed the limit (exactly 80 % fails). */

declare(strict_types=1);

$report = $argv[1] ?? dirname(__DIR__, 2) . '/coverage/clover.xml';
$limit = (float) ($argv[2] ?? 80);
$xml = new DOMDocument();
if (!is_file($report) || !$xml->load($report, LIBXML_NONET)) {
    fwrite(STDERR, "Missing or invalid Clover report: {$report}\n");
    exit(1);
}
$total = 0;
$covered = 0;
foreach ($xml->getElementsByTagName('line') as $line) {
    if ($line->getAttribute('type') !== 'stmt') {
        continue;
    }
    ++$total;
    if ((int) $line->getAttribute('count') > 0) {
        ++$covered;
    }
}
$percent = $total === 0 ? 0.0 : $covered * 100 / $total;
$message = sprintf('PHP line coverage %.2f %% (%d/%d), required > %s %%', $percent, $covered, $total, $limit);
if ($total === 0 || $covered * 100 <= $limit * $total) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
echo "OK: {$message}\n";
