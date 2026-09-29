<?php

namespace b10k\componentcheck\services;

/**
 * Decides what to test from Component Map's impact of a change.
 *
 * - Templates changed → the blocks (entry types) rendered through them.
 * - Files that change how pages look but are not templates — stylesheets,
 *   scripts, PHP, anything built into the web root → everything: any page can
 *   look different, and the map cannot say which.
 * - Files that do not reach the browser — docs, dotfiles (`.ddev/`,
 *   `.github/`, `.env`), package manifests and lockfiles, project config
 *   (Component Map already turns content model changes into blocks) → ignored,
 *   and listed.
 * - Nothing that renders a block → nothing to test.
 *
 * Only components that are actually used on a live page can be tested; the
 * rest are reported, not silently dropped.
 *
 * Craft-free and unit-tested; the same rules are mirrored in the watch runner
 * (runner/lib/watch-lib.mjs: selectTargets(), isFrontEndFile()).
 */
final class ChangeSelection
{
    public const ALL = 'all';
    public const SOME = 'some';
    public const NONE = 'none';

    private const IGNORED_EXTENSIONS = ['md', 'markdown', 'txt', 'rst', 'log', 'lock'];

    private const IGNORED_FILES = [
        'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
        'yarn.lock', 'pnpm-lock.yaml', 'bun.lockb', 'license', 'license.md',
    ];

    private const IGNORED_DIRECTORIES = ['storage', 'vendor', 'node_modules', 'config/project'];

    /**
     * @param array<string, mixed> $impact `component-map/impact --json`
     * @param string[] $known Components with usages on live pages.
     * @return array{mode: string, components: string[], untestable: string[], ignored: string[], reason: string}
     */
    public static function fromImpact(array $impact, array $known): array
    {
        $others = array_values(array_filter((array)($impact['unmapped'] ?? []), 'is_string'));
        $broad = array_values(array_filter($others, [self::class, 'isFrontEndFile']));
        $ignored = array_values(array_diff($others, $broad));
        $ignoredNote = $ignored !== [] ? ' Ignored, not front-end: ' . self::list($ignored) . '.' : '';

        if ($broad !== []) {
            return [
                'mode' => self::ALL,
                'components' => $known,
                'untestable' => [],
                'ignored' => $ignored,
                'reason' => 'Not only templates changed (' . self::list($broad) . ') — testing every component.' . $ignoredNote,
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
                'ignored' => $ignored,
                'reason' => ($affected === []
                    ? 'No block is rendered through the changed files.'
                    : 'The affected blocks (' . self::list($untestable) . ') are not used on any live page.') . $ignoredNote,
            ];
        }

        return [
            'mode' => self::SOME,
            'components' => $testable,
            'untestable' => $untestable,
            'ignored' => $ignored,
            'reason' => 'Changed files affect ' . self::list($testable)
                . ($untestable !== [] ? ' (not on any live page: ' . self::list($untestable) . ')' : '') . '.' . $ignoredNote,
        ];
    }

    /**
     * Whether a changed non-template file can change how pages look.
     */
    public static function isFrontEndFile(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $path);
        $base = strtolower((string)end($segments));

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                return false; // .ddev/, .github/, .env, .gitignore
            }
        }
        foreach (self::IGNORED_DIRECTORIES as $dir) {
            if ($path === $dir || str_starts_with($path, $dir . '/')) {
                return false;
            }
        }
        if (in_array($base, self::IGNORED_FILES, true)) {
            return false;
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        return !in_array($ext, self::IGNORED_EXTENSIONS, true);
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
