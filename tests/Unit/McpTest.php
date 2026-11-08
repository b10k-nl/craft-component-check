<?php

namespace b10k\componentcheck\tests\Unit;

use b10k\componentcheck\mcp\CoverageFindings;
use b10k\componentcheck\mcp\McpBrief;
use b10k\componentcheck\mcp\RunView;
use PHPUnit\Framework\TestCase;

/**
 * What an agent on Craft's MCP server is told and shown: the last run, the
 * instructions, the audit findings.
 */
class McpTest extends TestCase
{
    private const ROOT = '/var/www/html';

    /** @return array<string, mixed> */
    private function manifest(bool $markers = true): array
    {
        return [
            'viewports' => ['desktop' => ['width' => 1440, 'height' => 900], 'mobile' => ['width' => 390, 'height' => 844]],
            'markers' => $markers ? ['header' => 'X-Component-Check', 'token' => 'secret-token'] : null,
            'pages' => [
                ['id' => 'p1', 'url' => 'https://site.test/', 'title' => 'Home', 'components' => ['hero' => [123], 'richText' => [58, 128]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function results(): array
    {
        $ok = static fn(string $c, int $id) => ['check' => 'component-present', 'component' => $c, 'blockId' => $id, 'status' => 'passed', 'message' => 'Rendered'];
        return [
            'startedAt' => '2026-11-08T09:00:00.000Z',
            'finishedAt' => '2026-11-08T09:00:05.000Z',
            'snapshot' => ['action' => 'compare', 'dir' => self::ROOT . '/tests/component-check', 'comparedAgainst' => '2026-11-07T10:00:00Z', 'environment' => null],
            'runs' => [
                ['pageId' => 'p1', 'viewport' => 'desktop', 'checks' => [$ok('hero', 123), $ok('richText', 58)], 'artifacts' => []],
                ['pageId' => 'p1', 'viewport' => 'mobile', 'checks' => [
                    $ok('richText', 58),
                    ['check' => 'component-changed', 'component' => 'hero', 'blockId' => 123, 'status' => 'failed', 'message' => 'Changed since snapshot: h1 background #008000 → #0000ff'],
                ], 'artifacts' => ['trace' => self::ROOT . '/storage/component-check/latest/traces/p1-mobile.zip', 'components' => [
                    ['component' => 'hero', 'blockId' => 123, 'path' => self::ROOT . '/storage/component-check/latest/screenshots/hero/p1-mobile-123.png', 'before' => self::ROOT . '/tests/component-check/mobile/123.png'],
                ]]],
            ],
        ];
    }

    public function testFailuresComeFirstWithReasonsAndProjectRelativePaths(): void
    {
        $view = new RunView($this->manifest(), $this->results(), self::ROOT);
        $cases = $view->cases()['cases'];

        $this->assertSame(['hero', 'mobile', 'failed'], [$cases[0]['component'], $cases[0]['viewport'], $cases[0]['status']]);
        $this->assertSame(['Changed since snapshot: h1 background #008000 → #0000ff'], $cases[0]['reasons']);
        $this->assertSame('storage/component-check/latest/screenshots/hero/p1-mobile-123.png', $cases[0]['screenshot']);
        $this->assertSame('tests/component-check/mobile/123.png', $cases[0]['before']);
    }

    public function testTheMarkerTokenNeverLeaves(): void
    {
        $view = new RunView($this->manifest(), $this->results(), self::ROOT);
        $everything = json_encode([$view->summary(), $view->cases(), $view->snapshot()]);

        $this->assertStringNotContainsString('secret-token', (string)$everything);
        $this->assertStringNotContainsString(self::ROOT, (string)$everything);
    }

    public function testFiltersAndLimit(): void
    {
        $view = new RunView($this->manifest(), $this->results(), self::ROOT);

        $this->assertSame(1, $view->cases(status: 'failed')['total']);
        $this->assertSame(2, $view->cases(component: 'HERO')['total'], 'handles match case-insensitively');
        $this->assertSame(2, $view->cases(viewport: 'mobile')['total']);
        $limited = $view->cases(limit: 1);
        $this->assertCount(1, $limited['cases']);
        $this->assertTrue($limited['truncated']);
        $this->assertSame(4, $limited['total']);
    }

    public function testSummary(): void
    {
        $summary = (new RunView($this->manifest(), $this->results(), self::ROOT))->summary();

        $this->assertSame('failed', $summary['status']);
        $this->assertSame([3, 1], [$summary['passed'], $summary['failed']]);
        $this->assertSame(['hero', 'richText'], $summary['components']);
        $this->assertSame(['desktop', 'mobile'], $summary['viewports']);
        $this->assertTrue($summary['componentChecks']);
    }

    public function testUnmarkedComponentsAreTheOnesNeverLocated(): void
    {
        $results = $this->results();
        foreach ($results['runs'] as &$run) {
            foreach ($run['checks'] as &$check) {
                if ($check['component'] === 'richText') {
                    $check['status'] = 'skipped';
                }
            }
        }
        unset($run, $check);

        $this->assertSame(['richText'], (new RunView($this->manifest(), $results, self::ROOT))->unmarkedComponents());
        $this->assertSame([], (new RunView($this->manifest(false), $results, self::ROOT))->unmarkedComponents(), 'without markers at all, the summary says it once');
    }

    public function testBlockIdsOfAComponentOnAPage(): void
    {
        $view = new RunView($this->manifest(), $this->results(), self::ROOT);

        $this->assertSame([58, 128], $view->blockIds('richtext', 'https://site.test/'));
        $this->assertSame([], $view->blockIds('hero', 'https://site.test/other'));
    }

    public function testAStaleBaselineIsFoundPerBlockNotPerViewport(): void
    {
        $index = ['blocks' => [
            '123|desktop' => ['blockId' => 123, 'component' => 'hero', 'updated' => '2026-09-22T00:06:55-07:00'],
            '123|mobile' => ['blockId' => 123, 'component' => 'hero', 'updated' => '2026-09-22T00:06:55-07:00'],
            '58|desktop' => ['blockId' => 58, 'component' => 'richText', 'updated' => '2026-09-22T00:00:00+00:00'],
            '7|desktop' => ['blockId' => 7, 'component' => 'quote', 'updated' => '2026-09-22T00:00:00+00:00'],
        ]];
        $current = [
            123 => '2026-11-01T10:00:00+00:00', // edited since
            58 => '2026-09-22T00:00:00+00:00', // unchanged
            // 7 is gone
        ];

        $rows = CoverageFindings::rows($index, $current, ['cards'], 'tests/component-check');

        $this->assertSame(
            [['stale-baseline', 7], ['stale-baseline', 123], ['unmarked', null]],
            array_map(static fn(array $r) => [$r['type'], $r['blockId'] ?? null], $rows),
        );
        $this->assertStringContainsString('no longer exists', $rows[0]['message']);
        $this->assertStringContainsString('component-check/snapshot hero', $rows[1]['message']);
        $this->assertStringContainsString('cards', $rows[2]['message']);
    }

    public function testNoSnapshotNoStaleFindings(): void
    {
        $this->assertSame([], CoverageFindings::rows(null, [], [], null));
    }

    public function testInstructionsSayWhatOnlyARunKnows(): void
    {
        $view = new RunView($this->manifest(), $this->results(), self::ROOT);
        $run = $view->summary() + ['ago' => '2 h ago', 'stale' => true];
        $text = McpBrief::text(
            ['mode' => 'full', 'explain' => 'Mode: full (allowAdminChanges is true).'],
            $run,
            ['dir' => 'tests/component-check', 'local' => false, 'takenAt' => '2026-11-07T10:00:00Z', 'environment' => ['browser' => 'chromium 153', 'platform' => 'linux-arm64']],
        );

        $this->assertStringContainsString('Last run: 2026-11-08T09:00:05.000Z (2 h ago) — hero, richText on 1 page(s) × desktop, mobile: FAILED, 1 failed, 3 passed, 0 skipped.', $text);
        $this->assertStringContainsString('Templates changed after it', $text);
        $this->assertStringContainsString('a shared baseline, committed with the project): `tests/component-check`', $text);
        $this->assertStringContainsString('chromium 153 on linux-arm64', $text);
        $this->assertStringContainsString('only when the human agrees', $text);
        foreach (McpBrief::TOOLS as $tool) {
            $this->assertStringContainsString('`' . $tool . '`', $text);
        }
        $this->assertStringContainsString('`' . McpBrief::CHECK_ID . '`', $text);
    }

    public function testInstructionsWithoutARunOrTools(): void
    {
        $text = McpBrief::text(['mode' => 'readonly', 'explain' => 'Mode: readonly.'], null, null, false, false);

        $this->assertStringContainsString('No run yet on this environment.', $text);
        $this->assertStringNotContainsString('component-check.results', $text);
        $this->assertStringNotContainsString('content-model.audit', $text);
    }

    public function testAgo(): void
    {
        $now = strtotime('2026-11-08T12:00:00Z');
        $this->assertSame('just now', McpBrief::ago('2026-11-08T11:59:30Z', $now));
        $this->assertSame('30 min ago', McpBrief::ago('2026-11-08T11:30:00Z', $now));
        $this->assertSame('3 h ago', McpBrief::ago('2026-11-08T09:00:00Z', $now));
        $this->assertSame('5 days ago', McpBrief::ago('2026-11-03T12:00:00Z', $now));
        $this->assertNull(McpBrief::ago(null, $now));
    }
}
