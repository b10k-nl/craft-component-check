<?php

namespace b10k\componentcheck\mcp;

/**
 * Findings of the `component-check-coverage` check in Craft's
 * `content-model.audit`: where the content and Component Check no longer
 * line up, so checks are silently weaker than they look.
 *
 * - `stale-baseline`: a block in the snapshot whose content was edited after
 *   it, or that is gone. `test` skips such a block (an edit is not a
 *   regression), so a committed baseline quietly stops covering it until it
 *   is re-recorded.
 * - `unmarked`: an entry type the last run could not locate on any page —
 *   no markers around it — so it only gets page-level checks.
 *
 * Craft-free, so it can be tested; CoverageCheck gathers the inputs.
 */
final class CoverageFindings
{
    /**
     * @param array<string, mixed>|null $index The snapshot's index.json.
     * @param array<int, string|null> $current Block id → its dateUpdated now (ISO 8601); absent = deleted.
     * @param list<string> $unmarked From RunView::unmarkedComponents().
     * @param string|null $snapshotDir As shown to the agent (relative).
     * @return list<array<string, mixed>>
     */
    public static function rows(?array $index, array $current, array $unmarked, ?string $snapshotDir): array
    {
        $rows = [];

        // One finding per block, not per viewport: both viewports go stale together.
        $blocks = [];
        foreach ((array)($index['blocks'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['blockId'])) {
                continue;
            }
            $id = (int)$entry['blockId'];
            $blocks[$id] ??= $entry;
        }
        ksort($blocks);

        foreach ($blocks as $id => $entry) {
            $component = (string)($entry['component'] ?? '');
            if (!array_key_exists($id, $current)) {
                $rows[] = self::stale($component, $id, 'The block no longer exists.', $snapshotDir);
                continue;
            }
            $then = self::time($entry['updated'] ?? null);
            $now = self::time($current[$id]);
            // Instants, not strings: a changed site time zone is not an edit.
            if ($then !== null && $now !== null && $now !== $then) {
                $rows[] = self::stale($component, $id, 'Its content was edited after the snapshot, so `test` no longer compares it.', $snapshotDir);
            }
        }

        foreach ($unmarked as $component) {
            $rows[] = [
                'type' => 'unmarked',
                'component' => $component,
                'message' => sprintf(
                    'The last run found no markers around “%s” on any page: it only gets page-level checks, and is never compared with a snapshot. Wrap it in craft.componentCheck.start(block) / end() where it is rendered.',
                    $component,
                ),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stale(string $component, int $blockId, string $why, ?string $snapshotDir): array
    {
        return [
            'type' => 'stale-baseline',
            'component' => $component,
            'blockId' => $blockId,
            'message' => $why . ' Re-record it: php craft component-check/snapshot ' . ($component !== '' ? $component : '<component>') . '.',
            'snapshot' => $snapshotDir,
        ];
    }

    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : $time;
    }
}
