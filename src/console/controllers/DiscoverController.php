<?php

namespace b10k\componentregression\console\controllers;

use b10k\componentregression\services\ActivationPolicy;
use craft\helpers\Console;

/**
 * Where is every component used, and which pages would be tested?
 *
 *     php craft component-regression/discover
 *     php craft component-regression/discover hero
 *     php craft component-regression/discover --json
 *
 * Read-only: runs database queries, renders nothing, changes nothing. Allowed
 * in `readonly` and `full` mode.
 */
class DiscoverController extends BaseController
{
    /**
     * @var bool List every page a component is used on, not only the sample.
     */
    public bool $all = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all']);
    }

    /**
     * @param string $components Comma-separated component (entry type) handles. Empty = all.
     */
    public function actionIndex(string $components = ''): int
    {
        if (($refusal = $this->requireMode(ActivationPolicy::READONLY)) !== null) {
            return $refusal;
        }

        $runner = $this->plugin()->getTestRunner();
        ['samples' => $samples, 'discovery' => $discovery] = $runner->sample();

        $only = self::list($components);
        if ($only !== null && ($unknown = array_diff($only, array_keys($samples))) !== []) {
            return $this->error(
                'No usages found for: ' . implode(', ', $unknown) . '. Known components: ' . implode(', ', array_keys($samples)),
                ['known' => array_keys($samples)],
            );
        }

        if ($this->json) {
            $manifest = $runner->manifest($samples, $only, null, false);
            $manifest['usages'] = $this->all ? $this->usagesByComponent($discovery['usages'], $only) : null;
            $manifest['unused'] = $discovery['unused'];
            $manifest['skipped'] = $discovery['skipped'];
            $manifest['fields'] = $discovery['fields'];
            $this->writeJson($manifest);
            return self::EXIT_PASSED;
        }

        $this->stdout($this->plugin()->explainMode() . "\n", Console::FG_GREY);
        $this->stdout('Matrix fields: ' . ($discovery['fields'] === [] ? '(none)' : implode(', ', $discovery['fields'])) . "\n\n", Console::FG_GREY);

        $byComponent = $this->usagesByComponent($discovery['usages'], $only);

        foreach ($samples as $handle => $sample) {
            if ($only !== null && !in_array($handle, $only, true)) {
                continue;
            }

            $this->stdout(sprintf('%s (%s)', $sample->label, $handle), Console::BOLD);
            $this->stdout(sprintf(
                "  %d usage(s) on %d page(s), %d variant(s) → %d page(s) tested\n",
                $sample->usageCount,
                $sample->pageCount,
                $sample->variantCount,
                count($sample->pages),
            ), Console::FG_GREY);

            $pages = $this->all ? $byComponent[$handle] : $sample->pages;
            $tested = array_column($sample->pages, 'url');

            foreach ($pages as $page) {
                $mark = $this->all && in_array($page['url'], $tested, true) ? '•' : ' ';
                $this->stdout(sprintf("  %s %-40s %s\n", $mark, self::path($page['url']), $page['title']));
            }

            if ($sample->uncoveredVariants !== []) {
                $this->stdout(sprintf(
                    "    %d variant(s) not covered — raise maxPagesPerComponent to test them\n",
                    count($sample->uncoveredVariants),
                ), Console::FG_YELLOW);
            }
            $this->stdout("\n");
        }

        if ($samples === []) {
            $this->stdout("No Matrix blocks found on live pages.\n\n", Console::FG_YELLOW);
        }

        if ($discovery['unused'] !== [] && $only === null) {
            $this->stdout("Not used on any live page (nothing to test):\n", Console::FG_YELLOW);
            foreach ($discovery['unused'] as $unused) {
                $this->stdout(sprintf("  %s (%s) in %s\n", $unused['label'], $unused['component'], $unused['field']));
            }
            $this->stdout("\n");
        }

        if ($discovery['skipped'] !== []) {
            $parts = [];
            foreach ($discovery['skipped'] as $reason => $count) {
                $parts[] = "{$count} × {$reason}";
            }
            $this->stdout('Skipped blocks: ' . implode(', ', $parts) . "\n", Console::FG_GREY);
        }

        return self::EXIT_PASSED;
    }

    /**
     * @param \b10k\componentregression\models\Usage[] $usages
     * @param string[]|null $only
     * @return array<string, array<int, array{url: string, title: string}>>
     */
    private function usagesByComponent(array $usages, ?array $only): array
    {
        $pages = [];
        foreach ($usages as $usage) {
            if ($only !== null && !in_array($usage->component, $only, true)) {
                continue;
            }
            $pages[$usage->component][$usage->url] = ['url' => $usage->url, 'title' => $usage->pageTitle];
        }
        foreach ($pages as $component => $list) {
            ksort($list);
            $pages[$component] = array_values($list);
        }
        ksort($pages);
        return $pages;
    }
}
