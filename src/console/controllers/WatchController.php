<?php

namespace b10k\componentcheck\console\controllers;

use b10k\componentcheck\services\ActivationPolicy;
use Craft;
use craft\helpers\Console;

/**
 * Snapshots the components once, then re-checks them on every save.
 *
 *     php craft component-check/watch hero
 *     php craft component-check/watch hero,cards --viewport=mobile
 *     php craft component-check/watch --keep-snapshot
 *
 * Run it in its own terminal while you edit. The snapshot taken at start is
 * "how it was before I started"; every save is compared with it. Keys:
 * r = run again, s = new snapshot (accept the current state), q = quit.
 *
 * Content is discovered once, at start: restart after adding or editing
 * blocks in the control panel.
 */
class WatchController extends BrowserRunController
{
    /**
     * @var bool Compare with the existing snapshot instead of taking a new one.
     */
    public bool $keepSnapshot = false;

    /**
     * @var string Comma-separated directories or aliases to watch (default: the watchPaths setting).
     */
    public string $paths = '';

    public function options($actionID): array
    {
        // --json makes no sense for a long-running, interactive command.
        $options = array_diff(parent::options($actionID), ['json']);
        return array_values(array_merge($options, ['keepSnapshot', 'paths']));
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['keep-snapshot' => 'keepSnapshot']);
    }

    /**
     * @param string $components Comma-separated component (entry type) handles. Empty = all.
     */
    public function actionIndex(string $components = ''): int
    {
        if (($refusal = $this->requireMode(ActivationPolicy::FULL)) !== null) {
            return $refusal;
        }

        $plugin = $this->plugin();
        $settings = $plugin->getSettings();
        $runner = $plugin->getTestRunner();

        // A long session: the marker token lives for 12 hours instead of 1.
        $manifest = $this->prepareManifest($components, 12 * 3600);
        if (is_int($manifest)) {
            return $manifest;
        }
        if ($manifest['markers'] === null) {
            return $this->error('Watch mode needs component markers, but no signing key is configured (securityKey).');
        }

        if ($this->keepSnapshot && $runner->snapshotTakenAt() === null) {
            return $this->error('There is no snapshot to keep yet. Run without --keep-snapshot, or run component-check/snapshot first.');
        }

        $paths = [];
        $missing = [];
        foreach (self::list($this->paths) ?? $settings->watchPaths as $entry) {
            $resolved = self::resolvePath($entry);
            if ($resolved === null) {
                $missing[] = $entry;
                continue;
            }
            $paths[] = $resolved;
        }
        if ($paths === []) {
            return $this->error('Nothing to watch: ' . implode(', ', $missing) . ' not found. Set watchPaths or pass --paths.');
        }
        if ($missing !== []) {
            $this->stderr('Not watching (not found): ' . implode(', ', $missing) . "\n", Console::FG_YELLOW);
        }

        $exit = $runner->watch($manifest, array_values(array_unique($paths)), $settings->watchIgnore, $this->keepSnapshot, $this->headed);

        if ($exit === 127) {
            return $this->error('Could not start Node. Run `php craft component-check/doctor`.');
        }

        return $exit === 0 ? self::EXIT_PASSED : self::EXIT_ERROR;
    }

    /**
     * An alias (@templates, @webroot) or a path relative to the project root.
     */
    private static function resolvePath(string $entry): ?string
    {
        if (str_starts_with($entry, '@')) {
            $resolved = Craft::getAlias($entry, false);
            if ($resolved === false && $entry === '@webroot') {
                // Console requests often have no @webroot; Craft's default web root.
                $resolved = Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'web';
            }
        } else {
            $resolved = str_starts_with($entry, DIRECTORY_SEPARATOR)
                ? $entry
                : Craft::getAlias('@root') . DIRECTORY_SEPARATOR . $entry;
        }

        if (!is_string($resolved)) {
            return null;
        }
        $real = realpath($resolved);
        return $real !== false && is_dir($real) ? $real : null;
    }
}
