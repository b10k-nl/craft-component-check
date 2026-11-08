<?php

namespace b10k\componentcheck\mcp;

use b10k\componentcheck\services\ResultsReport;

/**
 * The last run as an agent on Craft's MCP server sees it: what it covered,
 * when, what failed and why — read from `latest/manifest.json` and
 * `results.json`, never handed over as they are.
 *
 * The manifest carries the signed marker token (valid for hours): nothing of
 * it leaves here but URLs, titles and component handles. Paths are made
 * relative to the project, so a remote agent does not learn the server's
 * layout and a local one can still open them.
 *
 * Craft-free, so it can be tested; LastRun reads the files.
 */
final class RunView
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 500;

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $results
     */
    public function __construct(
        private readonly array $manifest,
        private readonly array $results,
        private readonly string $root,
    ) {
    }

    public function report(): ResultsReport
    {
        return new ResultsReport($this->manifest, $this->results);
    }

    /**
     * When, what and how it went — no cases.
     *
     * @return array{startedAt: string|null, finishedAt: string|null, status: string, passed: int, failed: int, skipped: int, warnings: int, components: list<string>, pages: int, viewports: list<string>, componentChecks: bool, error: string|null}
     */
    public function summary(): array
    {
        $report = $this->report();
        $warnings = 0;
        foreach ($report->cases() as $case) {
            $warnings += count($case['warnings']);
        }
        $components = [];
        foreach ($this->manifest['pages'] ?? [] as $page) {
            foreach (array_keys($page['components'] ?? []) as $component) {
                $components[(string)$component] = true;
            }
        }
        ksort($components);

        return [
            'startedAt' => is_string($this->results['startedAt'] ?? null) ? $this->results['startedAt'] : null,
            'finishedAt' => is_string($this->results['finishedAt'] ?? null) ? $this->results['finishedAt'] : null,
            'status' => $report->status(),
            'passed' => $report->count(ResultsReport::PASSED),
            'failed' => $report->count(ResultsReport::FAILED),
            'skipped' => $report->count(ResultsReport::SKIPPED),
            'warnings' => $warnings,
            'components' => array_keys($components),
            'pages' => count($this->manifest['pages'] ?? []),
            'viewports' => array_map('strval', array_keys($this->manifest['viewports'] ?? [])),
            // Without markers only page-level checks ran: nothing per block, no snapshot comparison.
            'componentChecks' => !empty($this->manifest['markers']),
            'error' => is_string($this->results['error'] ?? null) ? $this->results['error'] : null,
        ];
    }

    /**
     * Cases (component × page × viewport), filtered, at most $limit.
     *
     * @return array{cases: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function cases(string $component = '', string $status = '', string $viewport = '', int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $matching = [];
        foreach ($this->report()->cases() as $case) {
            if ($component !== '' && strcasecmp($case['component'], $component) !== 0) {
                continue;
            }
            if ($status !== '' && $case['status'] !== $status) {
                continue;
            }
            if ($viewport !== '' && $case['viewport'] !== $viewport) {
                continue;
            }
            $matching[] = $case;
        }

        // What needs attention first: failures, then cases with warnings.
        usort($matching, static function(array $a, array $b): int {
            $rank = static fn(array $c): int => $c['status'] === ResultsReport::FAILED ? 0 : ($c['warnings'] !== [] ? 1 : 2);
            return $rank($a) <=> $rank($b);
        });

        $out = [];
        foreach (array_slice($matching, 0, $limit) as $case) {
            $out[] = [
                'component' => $case['component'],
                'url' => $case['url'],
                'title' => $case['title'],
                'viewport' => $case['viewport'],
                'status' => $case['status'],
                'reasons' => $case['reasons'],
                'warnings' => $case['warnings'],
                'screenshot' => $this->relative($case['screenshot']),
                'before' => $this->relative($case['before']),
                'trace' => $this->relative($case['trace']),
            ];
        }

        return ['cases' => $out, 'total' => count($matching), 'truncated' => count($matching) > $limit];
    }

    /**
     * What the run compared against, if anything.
     *
     * @return array{dir: string|null, comparedAgainst: string|null, environment: array<string, mixed>|null, note: string|null}|null
     */
    public function snapshot(): ?array
    {
        $snapshot = $this->results['snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['action'] ?? null) !== 'compare') {
            return null;
        }
        $environment = is_array($snapshot['environment'] ?? null) ? $snapshot['environment'] : null;

        return [
            'dir' => $this->relative(is_string($snapshot['dir'] ?? null) ? $snapshot['dir'] : null),
            'comparedAgainst' => is_string($snapshot['comparedAgainst'] ?? null) ? $snapshot['comparedAgainst'] : null,
            'environment' => $environment,
            'note' => ResultsReport::environmentNote($environment),
        ];
    }

    /**
     * Block ids of a component on a page, from the manifest.
     *
     * @return list<int>
     */
    public function blockIds(string $component, string $url): array
    {
        foreach ($this->manifest['pages'] ?? [] as $page) {
            if (($page['url'] ?? null) !== $url) {
                continue;
            }
            foreach ($page['components'] ?? [] as $handle => $ids) {
                if (strcasecmp((string)$handle, $component) === 0) {
                    return array_values(array_map('intval', (array)$ids));
                }
            }
        }
        return [];
    }

    /**
     * Components the run expected markers for but could not find on any page:
     * only page-level checks for them. Empty when the run had no markers at
     * all — then every component is in the same position, and the summary
     * says so once.
     *
     * @return list<string>
     */
    public function unmarkedComponents(): array
    {
        if (empty($this->manifest['markers'])) {
            return [];
        }
        $located = [];
        $seen = [];
        foreach ($this->results['runs'] ?? [] as $run) {
            foreach ($run['checks'] ?? [] as $check) {
                if (($check['check'] ?? null) !== 'component-present' || !is_string($check['component'] ?? null)) {
                    continue;
                }
                $seen[$check['component']] = true;
                if (($check['status'] ?? null) !== ResultsReport::SKIPPED) {
                    $located[$check['component']] = true;
                }
            }
        }
        $unmarked = array_keys(array_diff_key($seen, $located));
        sort($unmarked);
        return array_map('strval', $unmarked);
    }

    /**
     * A path under the project root, relative to it; anything else by its
     * file name only.
     */
    public function relative(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $path = str_replace('\\', '/', $path);
        if ($root !== '' && str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }
        return basename($path);
    }
}
