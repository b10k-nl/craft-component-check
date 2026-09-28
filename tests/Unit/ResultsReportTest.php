<?php

namespace b10k\componentcheck\tests\Unit;

use b10k\componentcheck\services\ResultsReport;
use PHPUnit\Framework\TestCase;

class ResultsReportTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'viewports' => ['desktop' => [], 'mobile' => []],
            'components' => ['hero' => ['label' => 'Hero'], 'cards' => ['label' => 'Cards']],
            'pages' => [
                ['id' => 'p1', 'url' => 'https://site.test/', 'title' => 'Home', 'components' => ['cards' => [7], 'hero' => [1]]],
                ['id' => 'p2', 'url' => 'https://site.test/about', 'title' => 'About', 'components' => ['hero' => [2]]],
            ],
        ];
    }

    private static function pageRun(string $page, string $viewport, array $checks = [], array $artifacts = []): array
    {
        return ['pageId' => $page, 'viewport' => $viewport, 'checks' => $checks, 'artifacts' => $artifacts];
    }

    public function testAllPassing(): void
    {
        $results = ['runs' => [
            self::pageRun('p1', 'desktop'), self::pageRun('p1', 'mobile'),
            self::pageRun('p2', 'desktop'), self::pageRun('p2', 'mobile'),
        ]];

        $report = new ResultsReport($this->manifest(), $results);

        $this->assertSame('passed', $report->status());
        $this->assertSame(6, $report->count('passed'));
        $this->assertSame(['desktop' => 'passed', 'mobile' => 'passed'], $report->toArray()['components']['hero']);
    }

    public function testComponentFailureOnlyFailsThatComponent(): void
    {
        $results = ['runs' => [
            self::pageRun('p1', 'desktop'),
            self::pageRun('p1', 'mobile', [
                ['check' => 'component-overflow', 'component' => 'hero', 'blockId' => 1, 'status' => 'failed', 'message' => 'overflows by 24px'],
            ], [
                'trace' => 'traces/p1-mobile.zip',
                'components' => [['component' => 'hero', 'blockId' => 1, 'path' => 'screenshots/hero/p1-mobile.png']],
            ]),
            self::pageRun('p2', 'desktop'), self::pageRun('p2', 'mobile'),
        ]];

        $report = new ResultsReport($this->manifest(), $results);
        $json = $report->toArray();

        $this->assertSame('failed', $json['status']);
        $this->assertSame(1, $json['failed']);
        $this->assertSame(5, $json['passed']);
        $this->assertSame(['desktop' => 'passed', 'mobile' => 'failed'], $json['components']['hero']);
        $this->assertSame(['desktop' => 'passed', 'mobile' => 'passed'], $json['components']['cards']);

        $failure = $json['failures'][0];
        $this->assertSame('hero', $failure['component']);
        $this->assertSame('mobile', $failure['viewport']);
        $this->assertSame(['overflows by 24px'], $failure['reasons']);
        $this->assertSame('screenshots/hero/p1-mobile.png', $failure['screenshot']);
        $this->assertSame('traces/p1-mobile.zip', $failure['trace']);
    }

    public function testPageFailureFailsEveryComponentOnThePage(): void
    {
        $results = ['runs' => [
            self::pageRun('p1', 'desktop', [
                ['check' => 'http-status', 'component' => null, 'status' => 'failed', 'message' => 'HTTP 500'],
            ], ['page' => 'screenshots/p1-desktop.png']),
            self::pageRun('p1', 'mobile'), self::pageRun('p2', 'desktop'), self::pageRun('p2', 'mobile'),
        ]];

        $json = (new ResultsReport($this->manifest(), $results))->toArray();

        $this->assertSame(2, $json['failed']);
        $this->assertSame('failed', $json['components']['cards']['desktop']);
        $this->assertSame('failed', $json['components']['hero']['desktop']);
        $this->assertSame('screenshots/p1-desktop.png', $json['failures'][0]['screenshot']);
    }

    public function testMissingRunsAreSkippedNotPassed(): void
    {
        $report = new ResultsReport($this->manifest(), ['runs' => [self::pageRun('p1', 'desktop')]]);

        $this->assertSame(2, $report->count('passed'));
        $this->assertSame(4, $report->count('skipped'));
        $this->assertSame('skipped', $report->toArray()['components']['hero']['mobile']);
    }

    public function testRunnerErrorFailsTheRun(): void
    {
        $report = new ResultsReport($this->manifest(), ['runs' => [], 'error' => 'Cannot find module playwright']);

        $this->assertSame('failed', $report->status());
        $this->assertStringContainsString('Cannot find module playwright', implode("\n", $report->toLines()));
    }

    public function testSkippedChecksDoNotFail(): void
    {
        $results = ['runs' => [
            self::pageRun('p2', 'desktop', [
                ['check' => 'component-present', 'component' => 'hero', 'status' => 'skipped', 'message' => 'no markers'],
            ]),
        ]];

        $cases = (new ResultsReport($this->manifest(), $results))->cases();
        $about = array_values(array_filter($cases, static fn($c) => $c['url'] === 'https://site.test/about' && $c['viewport'] === 'desktop'));
        $this->assertSame('passed', $about[0]['status']);
    }

    public function testTextReport(): void
    {
        $results = ['runs' => [
            self::pageRun('p1', 'desktop'),
            self::pageRun('p1', 'mobile', [
                ['check' => 'component-overflow', 'component' => 'hero', 'status' => 'failed', 'message' => 'overflows by 24px'],
            ]),
            self::pageRun('p2', 'desktop'), self::pageRun('p2', 'mobile'),
        ]];

        $text = implode("\n", (new ResultsReport($this->manifest(), $results))->toLines());

        $this->assertStringContainsString('Hero (hero)', $text);
        $this->assertStringContainsString('desktop ✓   mobile ✕', $text);
        $this->assertStringContainsString('✕ mobile: overflows by 24px', $text);
        $this->assertStringContainsString('FAILED — 5 passed, 1 failed, 0 skipped', $text);
    }
}
