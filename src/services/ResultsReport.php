<?php

namespace b10k\componentcheck\services;

/**
 * Reads the runner's results against the manifest and answers the questions a
 * developer (or an agent) actually asks: which component, on which page, on
 * which viewport, failed — and why.
 *
 * The unit of counting is a *case*: one component, on one page, on one
 * viewport. A page-level failure (HTTP error, uncaught exception) fails every
 * component case on that page, because none of them rendered in a state worth
 * trusting.
 */
final class ResultsReport
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /** @var array<int, array<string, mixed>> */
    private array $cases = [];

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $results
     */
    public function __construct(
        private readonly array $manifest,
        private readonly array $results,
    ) {
        $this->cases = $this->buildCases();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cases(): array
    {
        return $this->cases;
    }

    public function status(): string
    {
        if (!empty($this->results['error'])) {
            return self::FAILED;
        }
        return $this->count(self::FAILED) > 0 ? self::FAILED : self::PASSED;
    }

    public function count(string $status): int
    {
        return count(array_filter($this->cases, static fn(array $c) => $c['status'] === $status));
    }

    /**
     * Compact, stable JSON for agents and CI.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $components = [];
        foreach ($this->cases as $case) {
            $current = $components[$case['component']][$case['viewport']] ?? null;
            $components[$case['component']][$case['viewport']] = self::worse($current, $case['status']);
        }
        ksort($components);

        $failures = [];
        foreach ($this->cases as $case) {
            if ($case['status'] !== self::FAILED) {
                continue;
            }
            $failures[] = [
                'component' => $case['component'],
                'url' => $case['url'],
                'title' => $case['title'],
                'viewport' => $case['viewport'],
                'reasons' => $case['reasons'],
                'screenshot' => $case['screenshot'],
                'trace' => $case['trace'],
            ];
        }

        return [
            'status' => $this->status(),
            'passed' => $this->count(self::PASSED),
            'failed' => $this->count(self::FAILED),
            'skipped' => $this->count(self::SKIPPED),
            'error' => $this->results['error'] ?? null,
            'components' => $components,
            'failures' => $failures,
        ];
    }

    /**
     * Human-readable report, one line per component × page.
     *
     * @return string[]
     */
    public function toLines(): array
    {
        $lines = [];
        $viewports = array_keys($this->manifest['viewports'] ?? []);

        /** @var array<string, array<string, array<string, array<string, mixed>>>> $grouped component → url → viewport → case */
        $grouped = [];
        foreach ($this->cases as $case) {
            $grouped[$case['component']][$case['url']][$case['viewport']] = $case;
        }
        ksort($grouped);

        foreach ($grouped as $component => $byUrl) {
            $label = $this->manifest['components'][$component]['label'] ?? $component;
            $lines[] = "{$label} ({$component})";

            foreach ($byUrl as $url => $byViewport) {
                $first = reset($byViewport);
                $cells = [];
                foreach ($viewports as $viewport) {
                    $status = $byViewport[$viewport]['status'] ?? self::SKIPPED;
                    $cells[] = sprintf('%s %s', $viewport, self::symbol($status));
                }
                $lines[] = sprintf('  %-32s %s', self::truncate((string)$first['title'], 32), implode('   ', $cells));
                $lines[] = '    ' . self::path($url);

                foreach ($byViewport as $viewport => $case) {
                    if ($case['status'] !== self::FAILED) {
                        continue;
                    }
                    foreach ($case['reasons'] as $reason) {
                        $lines[] = "      ✕ {$viewport}: {$reason}";
                    }
                    if ($case['screenshot']) {
                        $lines[] = "        screenshot: {$case['screenshot']}";
                    }
                    if ($case['trace']) {
                        $lines[] = "        trace:      {$case['trace']}";
                    }
                }
            }
            $lines[] = '';
        }

        if (!empty($this->results['error'])) {
            $lines[] = 'Runner error: ' . $this->results['error'];
            $lines[] = '';
        }

        $lines[] = sprintf(
            '%s — %d passed, %d failed, %d skipped',
            strtoupper($this->status()),
            $this->count(self::PASSED),
            $this->count(self::FAILED),
            $this->count(self::SKIPPED),
        );

        return $lines;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCases(): array
    {
        /** @var array<string, array<string, array<string, mixed>>> $runs pageId → viewport → run */
        $runs = [];
        foreach ($this->results['runs'] ?? [] as $run) {
            $runs[$run['pageId']][$run['viewport']] = $run;
        }

        $cases = [];
        foreach ($this->manifest['pages'] ?? [] as $page) {
            foreach (array_keys($this->manifest['viewports'] ?? []) as $viewport) {
                $run = $runs[$page['id']][$viewport] ?? null;

                foreach (array_keys($page['components']) as $component) {
                    $cases[] = $this->caseFor($page, (string)$viewport, (string)$component, $run);
                }
            }
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed>|null $run
     * @return array<string, mixed>
     */
    private function caseFor(array $page, string $viewport, string $component, ?array $run): array
    {
        $case = [
            'component' => $component,
            'url' => $page['url'],
            'title' => $page['title'],
            'viewport' => $viewport,
            'status' => self::SKIPPED,
            'reasons' => [],
            'screenshot' => null,
            'trace' => null,
        ];

        if ($run === null) {
            $case['reasons'][] = 'not run';
            return $case;
        }

        $failed = false;
        foreach ($run['checks'] ?? [] as $check) {
            $appliesToCase = ($check['component'] ?? null) === null || $check['component'] === $component;
            if (!$appliesToCase || ($check['status'] ?? '') !== self::FAILED) {
                continue;
            }
            $failed = true;
            $case['reasons'][] = (string)($check['message'] ?? $check['check'] ?? 'failed');
        }

        $case['status'] = $failed ? self::FAILED : self::PASSED;

        if ($failed) {
            $case['trace'] = $run['artifacts']['trace'] ?? null;
            $case['screenshot'] = $run['artifacts']['page'] ?? null;
            foreach ($run['artifacts']['components'] ?? [] as $shot) {
                if (($shot['component'] ?? null) === $component) {
                    $case['screenshot'] = $shot['path'];
                    break;
                }
            }
        }

        return $case;
    }

    private static function worse(?string $a, string $b): string
    {
        $rank = [self::PASSED => 0, self::SKIPPED => 1, self::FAILED => 2];
        if ($a === null) {
            return $b;
        }
        return $rank[$b] > $rank[$a] ? $b : $a;
    }

    private static function symbol(string $status): string
    {
        return match ($status) {
            self::PASSED => '✓',
            self::FAILED => '✕',
            default => '–',
        };
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }

    private static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        return (is_string($path) && $path !== '' ? $path : '/') . (is_string($query) ? '?' . $query : '');
    }
}
