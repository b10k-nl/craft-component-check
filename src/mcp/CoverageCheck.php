<?php

namespace b10k\componentcheck\mcp;

use b10k\componentcheck\Plugin;
use b10k\componentcheck\services\TestRunner;
use craft\elements\Entry;
use craft\mcp\contentmodel\Check;

/**
 * Blocks the snapshot no longer covers and blocks without markers, as a check
 * of Craft's `content-model.audit` (Craft 5.12+), next to unused fields and
 * empty sections. See CoverageFindings.
 *
 * Reads the snapshot index, the last run and the blocks' dates — no browser.
 * Only loaded when Craft has MCP and its Check interface still matches:
 * Plugin registers it behind McpCompat::checkFits().
 */
class CoverageCheck implements Check
{
    public static function id(): string
    {
        return McpBrief::CHECK_ID;
    }

    /** @return array{count: int, findings: list<mixed>} */
    public function run(int $limit): array
    {
        $runner = Plugin::getInstance()->getTestRunner();
        $dir = $runner->snapshotDir();
        $index = TestRunner::readSnapshotIndex($dir);

        $ids = [];
        foreach ((array)($index['blocks'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['blockId'])) {
                $ids[(int)$entry['blockId']] = true;
            }
        }

        $current = [];
        if ($ids !== []) {
            /** @var Entry $block */
            foreach (Entry::find()->id(array_keys($ids))->status(null)->site('*')->unique()->all() as $block) {
                $current[(int)$block->id] = $block->dateUpdated?->format(DATE_ATOM);
            }
        }

        $rows = CoverageFindings::rows(
            $index,
            $current,
            LastRun::view()?->unmarkedComponents() ?? [],
            $index !== null ? (new RunView([], [], LastRun::root()))->relative($dir) : null,
        );

        return [
            'count' => count($rows),
            'findings' => array_slice($rows, 0, max(0, $limit)),
        ];
    }
}
