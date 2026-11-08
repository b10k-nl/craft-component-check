<?php

namespace b10k\componentcheck\mcp;

/**
 * What Component Check tells an agent connected to Craft's MCP server, in the
 * instructions `info.get` returns: what the plugin is for, the mode, how the
 * last run went, which snapshot it compares against, and how to run it.
 *
 * Only what the agent cannot work out from the content model or the files.
 * Kept free of Craft so the text can be tested; LastRun gathers the facts.
 */
final class McpBrief
{
    /** The id of the coverage check in `content-model.audit` (see CoverageCheck). */
    public const CHECK_ID = 'component-check-coverage';

    public const TOOLS = ['component-check.results', 'component-check.usages', 'component-check.screenshot'];

    /**
     * @param array{mode: string, explain: string} $mode
     * @param array<string, mixed>|null $run RunView::summary() plus `stale` (bool) and `ago` (string|null).
     * @param array{dir: string, local: bool, takenAt: string|null, environment: array<string, mixed>|null}|null $snapshot
     * @param bool $tools Our tools are on the server (see McpCompat).
     * @param bool $check The coverage check is in content-model.audit.
     */
    public static function text(array $mode, ?array $run, ?array $snapshot, bool $tools = true, bool $check = true): string
    {
        $lines = [
            '## Component Check',
            '',
            'Component Check finds the pages where each Matrix block (entry type) is used on the live site and checks those blocks in a real browser (Playwright, desktop and mobile): errors, missing or zero-size blocks, broken images, and — against a snapshot taken before a change — what changed, in words.',
            '',
            'Mode: ' . $mode['explain'],
        ];

        $lines[] = '';
        if ($run === null) {
            $lines[] = 'No run yet on this environment.';
        } else {
            $when = $run['finishedAt'] ?? 'an unknown time';
            $lines[] = sprintf(
                'Last run: %s%s — %s on %d page(s) × %s: %s, %d failed, %d passed, %d skipped%s.',
                $when,
                !empty($run['ago']) ? ' (' . $run['ago'] . ')' : '',
                $run['components'] === [] ? 'no components' : implode(', ', $run['components']),
                $run['pages'],
                implode(', ', $run['viewports']),
                strtoupper($run['status']),
                $run['failed'],
                $run['passed'],
                $run['skipped'],
                $run['warnings'] > 0 ? sprintf(', %d warning(s)', $run['warnings']) : '',
            );
            if (!empty($run['error'])) {
                $lines[] = 'The run did not complete: ' . $run['error'];
            }
            if (!empty($run['stale'])) {
                $lines[] = 'Templates changed after it: its results may be out of date — run it again before relying on them.';
            }
            if (empty($run['componentChecks'])) {
                $lines[] = 'It ran without block markers: page-level checks only, no per-block results or snapshot comparison.';
            }
        }

        if ($snapshot !== null) {
            $env = $snapshot['environment'] ?? null;
            $lines[] = '';
            $lines[] = sprintf(
                '%s `%s`%s%s.',
                $snapshot['local'] ? 'Snapshot (local to this machine):' : 'Snapshot (a shared baseline, committed with the project):',
                $snapshot['dir'],
                $snapshot['takenAt'] !== null ? ', last updated ' . $snapshot['takenAt'] : '',
                is_array($env) ? sprintf(', taken with %s on %s', $env['browser'] ?? 'an unknown browser', $env['platform'] ?? 'an unknown platform') : '',
            );
        }

        $lines[] = '';
        $lines[] = 'To check a change, from a shell in the project: `php craft component-check/snapshot <handle>` before you change a block, then `php craft component-check/test <handle> --json` after (`test --changed --json` tests only what uncommitted changes affect, with Component Map installed). Exit code 0 passed, 1 failed, 2 could not run.';
        $lines[] = 'Do not re-take a snapshot to make a failure go away. A committed baseline is the team\'s reference: re-record it only for an intended change, and only when the human agrees.';

        if ($tools) {
            $lines[] = '';
            $lines[] = 'Tools: `component-check.results` (the last run: what failed, where and why), `component-check.usages` (the live pages each block is on, and which of them a run tests), `component-check.screenshot` (a block before and after, as images).';
        }
        if ($check) {
            $lines[] = sprintf(
                'content-model.audit (admins) runs the check `%s`: blocks the snapshot no longer covers, and blocks without markers.',
                self::CHECK_ID,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * “3 h ago”, “2 days ago” — for a reader, not for parsing.
     */
    public static function ago(?string $iso, int $now): ?string
    {
        if ($iso === null || ($time = strtotime($iso)) === false) {
            return null;
        }
        $seconds = max(0, $now - $time);
        return match (true) {
            $seconds < 120 => 'just now',
            $seconds < 7200 => intdiv($seconds, 60) . ' min ago',
            $seconds < 172800 => intdiv($seconds, 3600) . ' h ago',
            default => intdiv($seconds, 86400) . ' days ago',
        };
    }
}
