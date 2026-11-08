<?php

namespace b10k\componentcheck\mcp;

use b10k\componentcheck\Plugin;
use b10k\componentcheck\services\TestRunner;
use Craft;
use craft\helpers\Json;

/**
 * Reads what the last run left in `latest/` and the snapshot folder, for the
 * MCP instructions, tools and audit check. Files only: no browser, no Node,
 * nothing written.
 */
final class LastRun
{
    /** Template files looked at, at most, to tell whether results are stale. */
    private const MAX_TEMPLATES = 20000;

    public static function root(): string
    {
        return (string)Craft::getAlias('@root');
    }

    /**
     * The last run, or null when there is none (or it left no results).
     */
    public static function view(): ?RunView
    {
        $dir = Plugin::getInstance()->getTestRunner()->outputDir();
        $manifest = self::json($dir . DIRECTORY_SEPARATOR . TestRunner::MANIFEST_FILE);
        $results = self::json($dir . DIRECTORY_SEPARATOR . TestRunner::RESULTS_FILE);
        if ($manifest === null || $results === null) {
            return null;
        }
        return new RunView($manifest, $results, self::root());
    }

    /**
     * RunView::summary() plus `ago` and `stale`.
     *
     * @return array<string, mixed>|null
     */
    public static function summary(?RunView $view = null): ?array
    {
        $view ??= self::view();
        if ($view === null) {
            return null;
        }
        $summary = $view->summary();
        $finished = $summary['finishedAt'] !== null ? strtotime($summary['finishedAt']) : false;
        $summary['ago'] = McpBrief::ago($summary['finishedAt'], time());
        $summary['stale'] = $finished !== false && self::templatesChangedSince($finished);
        return $summary;
    }

    /**
     * The snapshot `test` compares against now (setting or default).
     *
     * @return array{dir: string, local: bool, takenAt: string|null, environment: array<string, mixed>|null, blocks: int}|null
     */
    public static function snapshot(): ?array
    {
        $runner = Plugin::getInstance()->getTestRunner();
        $dir = $runner->snapshotDir();
        $index = TestRunner::readSnapshotIndex($dir);
        if ($index === null) {
            return null;
        }
        $local = $dir === $runner->defaultSnapshotDir();

        return [
            'dir' => (new RunView([], [], self::root()))->relative($dir) ?? $dir,
            'local' => $local,
            'takenAt' => is_string($index['updatedAt'] ?? null) ? $index['updatedAt'] : null,
            'environment' => is_array($index['environment'] ?? null) ? $index['environment'] : null,
            'blocks' => count((array)($index['blocks'] ?? [])),
        ];
    }

    /**
     * A template saved after the run: its results may no longer describe the
     * site. Bounded, so a huge templates folder cannot slow every connection.
     */
    private static function templatesChangedSince(int $time): bool
    {
        $path = Craft::$app->getPath()->getSiteTemplatesPath();
        if (!is_dir($path)) {
            return false;
        }
        $seen = 0;
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (++$seen > self::MAX_TEMPLATES) {
                    return false;
                }
                /** @var \SplFileInfo $file */
                if ($file->isFile() && $file->getMTime() > $time) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function json(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = Json::decodeIfJson((string)file_get_contents($file));
        return is_array($data) ? $data : null;
    }
}
