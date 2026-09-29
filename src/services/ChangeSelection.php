<?php

namespace b10k\componentcheck\services;

/**
 * Decides what to test from Component Map's impact of a change.
 *
 * - Templates changed → the blocks (entry types) rendered through them.
 * - CSS, JS or PHP changed (`unmapped`) → everything: any page can look
 *   different, and the map cannot say which.
 * - A block's content model changed in project config → that block, which
 *   impact already reports among `entryTypes`.
 * - Nothing that renders a block → nothing to test.
 *
 * Only components that are actually used on a live page can be tested; the
 * rest are reported, not silently dropped.
 *
 * Craft-free and unit-tested; the same rules are mirrored in the watch runner
 * (runner/lib/watch-lib.mjs, selectTargets()).
 */
final class ChangeSelection
{
    public const ALL = 'all';
    public const SOME = 'some';
    public const NONE = 'none';

    /**
     * @param array<string, mixed> $impact `component-map/impact --json`
     * @param string[] $known Components with usages on live pages.
     * @return array{mode: string, components: string[], untestable: string[], reason: string}
     */
    public static function fromImpact(array $impact, array $known): array
    {
        $unmapped = array_values(array_filter((array)($impact['unmapped'] ?? []), 'is_string'));
        if ($unmapped !== []) {
            return [
                'mode' => self::ALL,
                'components' => $known,
                'untestable' => [],
                'reason' => 'Not only templates changed (' . self::list($unmapped) . ') — testing every component.',
            ];
        }

        $affected = array_values(array_unique(array_filter((array)($impact['entryTypes'] ?? []), 'is_string')));
        sort($affected);
        $testable = array_values(array_intersect($affected, $known));
        $untestable = array_values(array_diff($affected, $known));

        if ($testable === []) {
            return [
                'mode' => self::NONE,
                'components' => [],
                'untestable' => $untestable,
                'reason' => $affected === []
                    ? 'No block is rendered through the changed files.'
                    : 'The affected blocks (' . self::list($untestable) . ') are not used on any live page.',
            ];
        }

        return [
            'mode' => self::SOME,
            'components' => $testable,
            'untestable' => $untestable,
            'reason' => 'Changed files affect ' . self::list($testable)
                . ($untestable !== [] ? ' (not on any live page: ' . self::list($untestable) . ')' : '') . '.',
        ];
    }

    /**
     * @param string[] $items
     */
    private static function list(array $items, int $max = 3): string
    {
        $shown = implode(', ', array_slice($items, 0, $max));
        return count($items) > $max ? $shown . ' +' . (count($items) - $max) . ' more' : $shown;
    }
}
