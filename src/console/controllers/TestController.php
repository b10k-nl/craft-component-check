<?php

namespace b10k\componentregression\console\controllers;

use b10k\componentregression\services\ActivationPolicy;
use b10k\componentregression\services\ResultsReport;
use craft\helpers\Console;

/**
 * Runs browser checks on the pages where components are actually used.
 *
 *     php craft component-regression/test
 *     php craft component-regression/test hero
 *     php craft component-regression/test hero,cards --viewport=mobile
 *     php craft component-regression/test --json
 *
 * Exit codes: 0 passed, 1 regressions found, 2 could not run.
 */
class TestController extends BaseController
{
    /**
     * @var string Comma-separated viewport names (default: all configured).
     */
    public string $viewport = '';

    /**
     * @var bool Show the browser window (local debugging).
     */
    public bool $headed = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['viewport', 'headed']);
    }

    /**
     * @param string $components Comma-separated component (entry type) handles. Empty = all.
     */
    public function actionIndex(string $components = ''): int
    {
        if (($refusal = $this->requireMode(ActivationPolicy::READONLY)) !== null) {
            return $refusal;
        }

        $plugin = $this->plugin();
        $runner = $plugin->getTestRunner();
        $settings = $plugin->getSettings();

        $viewports = self::list($this->viewport);
        if ($viewports !== null && ($unknown = array_diff($viewports, array_keys($settings->viewports))) !== []) {
            return $this->error(
                'Unknown viewport(s): ' . implode(', ', $unknown) . '. Configured: ' . implode(', ', array_keys($settings->viewports)),
            );
        }

        ['samples' => $samples] = $runner->sample();

        $only = self::list($components);
        if ($only !== null && ($unknown = array_diff($only, array_keys($samples))) !== []) {
            return $this->error(
                'No usages found for: ' . implode(', ', $unknown) . '. Known components: ' . implode(', ', array_keys($samples)),
                ['known' => array_keys($samples)],
            );
        }

        $manifest = $runner->manifest($samples, $only, $viewports, true);

        if ($manifest['pages'] === []) {
            return $this->error('Nothing to test: no component is used on a live page. Run `php craft component-regression/discover`.');
        }

        if (!$this->json) {
            $this->stdout(sprintf(
                "Testing %d component(s) on %d page(s) × %d viewport(s)%s\n\n",
                count($manifest['components']),
                count($manifest['pages']),
                count($manifest['viewports']),
                $manifest['markers'] === null ? ' — page-level checks only (markers need mode “full”)' : '',
            ), Console::FG_GREY);
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
