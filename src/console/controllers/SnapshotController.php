<?php

namespace b10k\componentcheck\console\controllers;

use b10k\componentcheck\services\ActivationPolicy;
use craft\helpers\Console;
use craft\helpers\FileHelper;

/**
 * Records how blocks look *now*, so the next `test` can say what changed.
 *
 *     php craft component-check/snapshot hero      ← before you change the Hero
 *     …edit templates / CSS…
 *     php craft component-check/test hero          ← what changed since
 *
 * Kept locally in storage/component-check/snapshot — per machine, per
 * database, never committed. Snapshotting some components keeps the others;
 * `--reset` starts over.
 *
 * Needs mode `full`: blocks are located by their markers.
 */
class SnapshotController extends BrowserRunController
{
    /**
     * @var bool Delete the existing snapshot first.
     */
    public bool $reset = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['reset']);
    }

    /**
     * @param string $components Comma-separated component (entry type) handles. Empty = all.
     */
    public function actionIndex(string $components = ''): int
    {
        if (($refusal = $this->requireMode(ActivationPolicy::FULL)) !== null) {
            return $refusal;
        }

        $manifest = $this->prepareManifest($components);
        if (is_int($manifest)) {
            return $manifest;
        }
        if ($manifest['markers'] === null) {
            return $this->error('Snapshots need component markers, but no signing key is configured (securityKey).');
        }

        $runner = $this->plugin()->getTestRunner();
        $dir = $runner->snapshotDir();
        if ($this->reset && is_dir($dir)) {
            FileHelper::removeDirectory($dir);
        }
        $manifest['snapshot'] = ['action' => 'record', 'dir' => $dir];

        if (!$this->json) {
            $this->stdout(sprintf(
                "Snapshotting %d component(s) on %d page(s) × %d viewport(s)\n\n",
                count($manifest['components']),
                count($manifest['pages']),
                count($manifest['viewports']),
            ), Console::FG_GREY);
        }

        $run = $runner->run($manifest, $this->json, $this->headed);
        $results = $run['results'];

        if (!empty($results['error'])) {
            return $this->error('Runner error: ' . $results['error']);
        }

        $recorded = (int)($results['snapshot']['recorded'] ?? 0);
        $failures = 0;
        foreach ($results['runs'] ?? [] as $r) {
            if (($r['status'] ?? '') === 'failed') {
                $failures++;
            }
        }

        if ($this->json) {
            $this->writeJson([
                'status' => $recorded > 0 ? 'ok' : 'error',
                'recorded' => $recorded,
                'dir' => $dir,
                'failedRuns' => $failures,
            ]);
        } else {
            $this->stdout("\n");
            if ($recorded === 0) {
                $this->stderr("No block could be located — are the markers in the block loop? Run component-check/doctor.\n", Console::FG_RED);
            } else {
                $this->stdout("Saved {$recorded} block snapshot(s) to {$dir}\n", Console::FG_GREEN);
                $this->stdout("Now make your change and run: php craft component-check/test" . ($components !== '' ? " {$components}" : '') . "\n");
            }
            if ($failures > 0) {
                $this->stdout(
                    "Note: {$failures} page/viewport run(s) already fail right now — the snapshot records them as they are.\n",
                    Console::FG_YELLOW,
                );
            }
        }

        return $recorded > 0 ? self::EXIT_PASSED : self::EXIT_ERROR;
    }
}
