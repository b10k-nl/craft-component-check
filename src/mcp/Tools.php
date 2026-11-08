<?php

namespace b10k\componentcheck\mcp;

use b10k\componentcheck\models\ComponentSample;
use b10k\componentcheck\Plugin;
use b10k\componentcheck\services\ActivationPolicy;
use b10k\componentcheck\services\TestRunner;
use Craft;
use craft\mcp\attributes\RequiresPermission;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\ToolAnnotations;

/**
 * Component Check's tools on Craft's MCP server (Craft 5.12+): what only a
 * browser run knows — the last run's results, the pages each block is tested
 * on, and a block's picture before and after a change.
 *
 * All three read only: they never start a browser, write a snapshot or change
 * content. Running the checks stays a shell command (`component-check/test`).
 * Hidden where the mode is `off` (Plugin) and from users without control-panel
 * access; each call checks again.
 */
class Tools
{
    /** Images returned per call, at most. */
    public const MAX_IMAGES = 4;

    /** Bytes per image, at most; a full-page screenshot can be far larger. */
    public const MAX_IMAGE_BYTES = 3_000_000;

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'component-check.results',
        description: 'The last Component Check run on this environment: when it ran, what it covered, and every case (block × page × viewport) with its verdict — failed with the reasons in words (an error, a missing block, “h1 background #008000 → #0000ff” against the snapshot), warnings, and paths to the screenshots. Failures first. Says whether templates changed since, so the results may be stale. Does not run anything: a run is `php craft component-check/test --json` in a shell.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function results(
        #[Schema(description: 'An entry type handle, to show one block\'s cases only. Omit for all.')]
        string $component = '',
        #[Schema(description: 'Only cases with this verdict.', enum: ['all', 'failed', 'passed', 'skipped'])]
        string $status = 'all',
        #[Schema(description: 'Only this viewport, e.g. mobile.')]
        string $viewport = '',
        #[Schema(description: 'Cases returned at most.', minimum: 1, maximum: RunView::MAX_LIMIT)]
        int $limit = RunView::DEFAULT_LIMIT,
    ): array {
        $this->guard();
        $view = LastRun::view();
        if ($view === null) {
            return [
                'run' => null,
                'message' => 'No run yet on this environment. Run `php craft component-check/test --json` in a shell (take `component-check/snapshot` first to compare before and after a change).',
            ];
        }

        $cases = $view->cases($component, $status === 'all' ? '' : $status, $viewport, $limit);
        if ($component !== '' && $cases['total'] === 0 && !in_array(strtolower($component), array_map('strtolower', $view->summary()['components']), true)) {
            throw new ToolCallException(sprintf(
                'The last run did not cover “%s”. It covered: %s. Run `php craft component-check/test %s --json` to test it.',
                $component,
                implode(', ', $view->summary()['components']) ?: 'nothing',
                $component,
            ));
        }

        return [
            'run' => LastRun::summary($view),
            'snapshot' => $view->snapshot(),
            'cases' => $cases['cases'],
            'total' => $cases['total'],
            'truncated' => $cases['truncated'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'component-check.usages',
        description: 'Where each Matrix block (entry type) is used on the live site — usages, pages, variants (which fields are filled in) — and the few pages a Component Check run tests so every variant is covered. Use it to know which pages to look at after changing a block. Reads the database; renders nothing.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function usages(
        #[Schema(description: 'An entry type handle. Omit for every block.')]
        string $component = '',
        #[Schema(description: 'List every page the block is on, not only the ones a run tests.')]
        bool $all = false,
        #[Schema(description: 'Pages listed per block, at most.', minimum: 1, maximum: RunView::MAX_LIMIT)]
        int $limit = RunView::DEFAULT_LIMIT,
    ): array {
        $this->guard();
        $limit = max(1, min(RunView::MAX_LIMIT, $limit));
        ['samples' => $samples, 'discovery' => $discovery] = Plugin::getInstance()->getTestRunner()->sample();

        if ($component !== '') {
            $match = null;
            foreach (array_keys($samples) as $handle) {
                if (strcasecmp((string)$handle, $component) === 0) {
                    $match = $handle;
                }
            }
            if ($match === null) {
                foreach ($discovery['unused'] as $unused) {
                    if (strcasecmp((string)$unused['component'], $component) === 0) {
                        return ['blocks' => [], 'unused' => [$unused], 'message' => sprintf('“%s” is allowed in %s but not used on any live page: nothing to test.', $component, $unused['field'])];
                    }
                }
                throw new ToolCallException(sprintf(
                    'No block “%s” on a live page. Blocks in use: %s.',
                    $component,
                    implode(', ', array_keys($samples)) ?: 'none',
                ));
            }
            $samples = [$match => $samples[$match]];
        }

        $allPages = [];
        if ($all) {
            foreach ($discovery['usages'] as $usage) {
                $allPages[$usage->component][$usage->url] = ['url' => $usage->url, 'title' => $usage->pageTitle];
            }
        }

        $blocks = [];
        foreach ($samples as $handle => $sample) {
            /** @var ComponentSample $sample */
            $tested = array_map(static fn(array $p): array => ['url' => $p['url'], 'title' => $p['title']], $sample->pages);
            $block = [
                'component' => (string)$handle,
                'label' => $sample->label,
                'usages' => $sample->usageCount,
                'pages' => $sample->pageCount,
                'variants' => $sample->variantCount,
                'tested' => array_slice($tested, 0, $limit),
                'variantsNotCovered' => count($sample->uncoveredVariants),
            ];
            if ($all) {
                $pages = array_values($allPages[$handle] ?? []);
                $block['allPages'] = array_slice($pages, 0, $limit);
                $block['allPagesTruncated'] = count($pages) > $limit;
            }
            $blocks[] = $block;
        }

        return [
            'blocks' => $blocks,
            'unused' => $component === '' ? $discovery['unused'] : [],
            'fields' => $discovery['fields'],
        ];
    }

    /**
     * @return list<TextContent|ImageContent>
     */
    #[McpTool(
        name: 'component-check.screenshot',
        description: 'A block as Component Check saw it, as images: “now” from the last run (kept only when the block failed) and “before” from the snapshot. For a failed case, the two side by side show what a change did. Pick the page and viewport, or leave them out for the block\'s first failing case (or its first page).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function screenshot(
        #[Schema(description: 'An entry type handle, as component-check.results lists them.')]
        string $component,
        #[Schema(description: 'The page: its URL or path, e.g. /about. Omit for the first failing case.')]
        string $page = '',
        #[Schema(description: 'A viewport, e.g. mobile. Omit for every viewport.')]
        string $viewport = '',
        #[Schema(description: 'Which picture.', enum: ['both', 'now', 'before'])]
        string $which = 'both',
    ): array {
        $this->guard();
        $runner = Plugin::getInstance()->getTestRunner();
        $view = LastRun::view();
        $snapshotDir = $runner->snapshotDir();
        if ($view !== null && is_string($compared = $this->snapshotDirOfRun($view))) {
            $snapshotDir = $compared;
        }
        $index = TestRunner::readSnapshotIndex($snapshotDir) ?? ['blocks' => []];
        $allowed = [$runner->outputDir(), $snapshotDir];

        // Pages of this block: from the last run, or else from the snapshot.
        $cases = $view !== null ? $view->cases($component, '', $viewport, RunView::MAX_LIMIT)['cases'] : [];
        $targets = [];
        foreach ($cases as $case) {
            $targets[] = ['url' => $case['url'], 'viewport' => $case['viewport'], 'case' => $case];
        }
        if ($targets === []) {
            foreach ((array)$index['blocks'] as $entry) {
                if (is_array($entry) && strcasecmp((string)($entry['component'] ?? ''), $component) === 0
                    && ($viewport === '' || ($entry['viewport'] ?? null) === $viewport)) {
                    $targets[] = ['url' => (string)($entry['url'] ?? ''), 'viewport' => (string)$entry['viewport'], 'case' => null];
                }
            }
        }
        if ($targets === []) {
            throw new ToolCallException(sprintf(
                'Nothing recorded for “%s”%s: not in the last run, not in the snapshot. Run `php craft component-check/snapshot %s` or `component-check/test %s` in a shell first.',
                $component,
                $viewport !== '' ? " on {$viewport}" : '',
                $component,
                $component,
            ));
        }

        // The page: as asked, else the first failing case, else the first.
        $url = null;
        if ($page !== '') {
            foreach ($targets as $t) {
                if ($t['url'] === $page || rtrim((string)parse_url($t['url'], PHP_URL_PATH), '/') === rtrim($page, '/')) {
                    $url = $t['url'];
                    break;
                }
            }
            if ($url === null) {
                throw new ToolCallException(sprintf(
                    '“%s” was not checked on %s. Pages: %s.',
                    $component,
                    $page,
                    implode(', ', array_unique(array_column($targets, 'url'))),
                ));
            }
        } else {
            foreach ($targets as $t) {
                if (($t['case']['status'] ?? null) === 'failed') {
                    $url = $t['url'];
                    break;
                }
            }
            $url ??= $targets[0]['url'];
        }

        $content = [];
        $images = 0;
        foreach ($targets as $t) {
            if ($t['url'] !== $url) {
                continue;
            }
            $case = $t['case'];
            $label = sprintf('%s on %s, %s', $component, $url, $t['viewport']);
            $content[] = new TextContent($case !== null
                ? sprintf('%s — %s%s', $label, $case['status'], $case['reasons'] !== [] ? ': ' . implode('; ', $case['reasons']) : '')
                : $label . ' — in the snapshot, not in the last run');

            if ($which !== 'before') {
                $now = $case !== null && $case['screenshot'] !== null ? LastRun::root() . '/' . $case['screenshot'] : null;
                $content[] = $this->image('Now', $now, $allowed, $images, $case === null || $case['status'] !== 'failed'
                    ? 'Now: no picture — the run keeps one only for a failed block.'
                    : 'Now: the screenshot is missing.');
            }
            if ($which !== 'now') {
                $before = $case !== null && $case['before'] !== null ? LastRun::root() . '/' . $case['before'] : null;
                if ($before === null) {
                    foreach ($view?->blockIds($component, $url) ?? [] as $id) {
                        $entry = $index['blocks'][$id . '|' . $t['viewport']] ?? null;
                        if (is_array($entry) && is_string($entry['screenshot'] ?? null)) {
                            $before = $snapshotDir . DIRECTORY_SEPARATOR . $entry['screenshot'];
                            break;
                        }
                    }
                }
                if ($before === null && $case === null) {
                    foreach ((array)$index['blocks'] as $entry) {
                        if (is_array($entry) && ($entry['url'] ?? null) === $url && ($entry['viewport'] ?? null) === $t['viewport']
                            && strcasecmp((string)($entry['component'] ?? ''), $component) === 0 && is_string($entry['screenshot'] ?? null)) {
                            $before = $snapshotDir . DIRECTORY_SEPARATOR . $entry['screenshot'];
                            break;
                        }
                    }
                }
                $content[] = $this->image('Before (snapshot)', $before, $allowed, $images, 'Before: no snapshot of this block.');
            }
        }

        return $content;
    }

    /**
     * A caption and the image, or a line saying why there is none. Only PNGs
     * inside our own folders, whatever the results file says.
     *
     * @param list<string> $allowed
     * @return TextContent|ImageContent
     */
    private function image(string $caption, ?string $path, array $allowed, int &$images, string $missing): TextContent|ImageContent
    {
        if ($path === null) {
            return new TextContent($missing);
        }
        $real = realpath($path);
        $inside = false;
        foreach ($allowed as $dir) {
            $base = realpath($dir);
            if ($real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                $inside = true;
            }
        }
        if (!$inside || !str_ends_with(strtolower((string)$real), '.png') || !is_file((string)$real)) {
            return new TextContent($missing);
        }
        if (filesize((string)$real) > self::MAX_IMAGE_BYTES) {
            return new TextContent(sprintf('%s: too large to send (%d KB) — open %s.', $caption, intdiv((int)filesize((string)$real), 1024), (new RunView([], [], LastRun::root()))->relative((string)$real)));
        }
        if ($images >= self::MAX_IMAGES) {
            return new TextContent(sprintf('%s: left out — %d images per call at most. Ask for one viewport.', $caption, self::MAX_IMAGES));
        }
        $images++;
        return ImageContent::fromFile((string)$real, 'image/png');
    }

    private function snapshotDirOfRun(RunView $view): ?string
    {
        $snapshot = $view->snapshot();
        if ($snapshot === null || $snapshot['dir'] === null) {
            return null;
        }
        $dir = LastRun::root() . '/' . $snapshot['dir'];
        return is_dir($dir) ? $dir : null;
    }

    /**
     * Discovery already hides these tools where the mode is off and from
     * users without control-panel access; a session that lost either since
     * is told so.
     */
    private function guard(): void
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null || $plugin->getMode() === ActivationPolicy::OFF) {
            throw new ToolCallException('Component Check is off on this environment.');
        }
        if (!Craft::$app->getUser()->checkPermission('accessCp')) {
            throw new ToolCallException('You do not have access to Component Check.');
        }
    }
}
