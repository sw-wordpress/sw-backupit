<?php

declare(strict_types=1);

namespace SWBackupIt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWBackupIt\Core\Support\AtomicFile;

final class ReleaseWorkflowTest extends TestCase
{
    public static function releaseStates(): array
    {
        return [
            'new draft' => ['missing', 0],
            'existing draft' => ['draft', 0],
            'published release' => ['published', 1],
            'metadata request fails' => ['api-error', 1],
        ];
    }

    #[DataProvider('releaseStates')]
    public function testUploadResolvesDraftMetadataAfterUpload(string $state, int $expectedExit): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/main.yml');
        self::assertIsString($workflow);
        self::assertSame(1, preg_match('/      - name: Upload package to draft release\n.*?        run: \|\n(.*?)(?=\n      - name:)/s', $workflow, $match));
        $script = preg_replace('/^          /m', '', $match[1]);
        self::assertIsString($script);
        $root = sys_get_temp_dir() . '/swbi-release-workflow-' . bin2hex(random_bytes(6));
        mkdir($root . '/dist', 0700, true);
        try {
            file_put_contents($root . '/dist/sw-backupit-0.8.0.zip', 'disposable test package');
            file_put_contents($root . '/gh', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$RUNNER_TEMP/commands"
case "$*" in
  'release view 0.8.0 --json isDraft')
    case "$TEST_RELEASE_STATE" in
      missing) exit 1 ;;
      published) printf '{"isDraft":false}' ;;
      *) printf '{"isDraft":true}' ;;
    esac ;;
  'release create 0.8.0 --draft --verify-tag --title 0.8.0')
    test "$TEST_RELEASE_STATE" = missing ;;
  "release upload 0.8.0 $RUNNER_TEMP/dist/sw-backupit-0.8.0.zip --clobber")
    touch "$RUNNER_TEMP/uploaded" ;;
  'release view 0.8.0 --json apiUrl --jq .apiUrl')
    test -f "$RUNNER_TEMP/uploaded"
    printf 'https://api.github.com/repos/sw-wordpress/sw-backupit/releases/42\n' ;;
  'api https://api.github.com/repos/sw-wordpress/sw-backupit/releases/42')
    test -f "$RUNNER_TEMP/uploaded"
    if [ "$TEST_RELEASE_STATE" = api-error ]; then exit 1; fi
    printf '{"id":42,"draft":true,"assets":[{"id":56,"name":"sw-backupit-0.8.0.zip"}]}' ;;
  *) printf 'Unexpected GitHub request: %s\n' "$*" >&2; exit 1 ;;
esac
BASH
            );
            chmod($root . '/gh', 0700);
            $process = proc_open(['bash', '-e', '-o', 'pipefail', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
                'PATH' => $root . ':' . getenv('PATH'),
                'RUNNER_TEMP' => $root,
                'RELEASE_TAG' => '0.8.0',
                'GITHUB_REPOSITORY' => 'sw-wordpress/sw-backupit',
                'TEST_RELEASE_STATE' => $state,
            ]);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($expectedExit, proc_close($process), $output);
            if ($expectedExit === 0) {
                $release = json_decode(file_get_contents($root . '/release.json'), true, 32, JSON_THROW_ON_ERROR);
                self::assertSame(42, $release['id']);
                self::assertSame(56, $release['assets'][0]['id']);
            }
            if ($state === 'published') {
                self::assertFileDoesNotExist($root . '/uploaded');
                self::assertFileDoesNotExist($root . '/release.json');
                self::assertStringContainsString('already published', $output);
            }
        } finally {
            AtomicFile::removeTree($root);
        }
    }
}
