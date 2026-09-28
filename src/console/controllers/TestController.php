<?php

namespace b10k\componentcheck\console\controllers;

use b10k\componentcheck\services\ActivationPolicy;
use b10k\componentcheck\services\ResultsReport;
use craft\helpers\Console;

/**
 * Runs browser checks on the pages where components are actually used.
 *
 *     php craft component-check/test
 *     php craft component-check/test hero
 *     php craft component-check/test hero,cards --viewport=mobile
 *     php craft component-check/test --json
 *
 * If a snapshot exists (`component-check/snapshot`), every block is also
 * compared against it and anything that changed fails.
 *
 * Exit codes: 0 passed, 1 regressions found, 2 could not run.
 */
class TestController extends BrowserRunController
{
    /**
     * @var bool Do not compare against the snapshot, even if one exists.
     */
    public bool $noSnapshot = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['noSnapshot']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['no-snapshot' => 'noSnapshot']);
    }

    /**
     * @param string $components Comma-separated component (entry type) handles. Empty = all.
     */
    public function actionIndex(string $components = ''): int
    {
        if (($refusal = $this->requireMode(ActivationPolicy::READONLY)) !== null) {
            return $refusal;
        }

        $manifest = $this->prepareManifest($components);
        if (is_int($manifest)) {
            return $manifest;
        }

        $runner = $this->plugin()->getTestRunner();
        $snapshotAt = $this->noSnapshot || $manifest['markers'] === null ? null : $runner->snapshotTakenAt();
        if ($snapshotAt !== null) {
            $manifest['snapshot'] = ['action' => 'compare', 'dir' => $runner->snapshotDir()];
        }

        if (!$this->json) {
            $this->stdout(sprintf(
                "Testing %d component(s) on %d page(s) × %d viewport(s)%s\n",
                count($manifest['components']),
                count($manifest['pages']),
                count($manifest['viewports']),
                $manifest['markers'] === null ? ' — page-level checks only (markers need mode “full”)' : '',
            ), Console::FG_GREY);
            $this->stdout(
                $snapshotAt !== null
                    ? "Comparing with the snapshot from {$snapshotAt}\n\n"
                    : "No snapshot: checking for errors only. Run component-check/snapshot before a change to compare before/after.\n\n",
                Console::FG_GREY,
            );
        }

        $run = $runner->run($manifest, $this->json, $this->headed);
        $report = new ResultsReport($manifest, $run['results']);

        if ($this->json) {
            $this->writeJson($report->toArray() + [
                'outputDir' => $run['outputDir'],
                'manifest' => $run['manifestPath'],
                'results' => $run['resultsPath'],
            ]);
        } else {
            $this->stdout("\n");
            foreach ($report->toLines() as $line) {
                $color = match (true) {
                    str_contains($line, '✕'), str_starts_with($line, 'FAILED'), str_starts_with($line, 'Runner error') => Console::FG_RED,
                    str_contains($line, '⚠') => Console::FG_YELLOW,
                    str_starts_with($line, 'PASSED') => Console::FG_GREEN,
                    default => null,
                };
                $color === null ? $this->stdout($line . "\n") : $this->stdout($line . "\n", $color);
            }
            $this->stdout("\nArtifacts: {$run['outputDir']}\n", Console::FG_GREY);
        }

        if (!empty($run['results']['error'])) {
            return self::EXIT_ERROR;
        }

        return $report->status() === ResultsReport::PASSED ? self::EXIT_PASSED : self::EXIT_FAILED;
    }
}
