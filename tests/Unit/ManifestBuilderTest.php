<?php

namespace b10k\componentregression\tests\Unit;

use b10k\componentregression\models\ComponentSample;
use b10k\componentregression\services\ManifestBuilder;
use PHPUnit\Framework\TestCase;

class ManifestBuilderTest extends TestCase
{
    private const OPTIONS = [
        'viewports' => ['desktop' => ['width' => 1440, 'height' => 900]],
        'timeout' => 30000,
        'concurrency' => 2,
        'ignoreErrors' => [],
        'blockRequests' => ['googletagmanager.com'],
        'ignoreHttpsErrors' => true,
    ];

    /**
     * @return array<string, ComponentSample>
     */
    private function samples(): array
    {
        $page = static fn(string $url, array $ids) => [
            'url' => $url, 'title' => "T {$url}", 'pageId' => 1, 'site' => 'default',
            'blockIds' => $ids, 'variants' => ['v'],
        ];

        return [
            'cards' => new ComponentSample('cards', 'Cards', 3, 2, 1, [$page('https://site.test/', [7])]),
            'hero' => new ComponentSample('hero', 'Hero', 9, 9, 2, [
                $page('https://site.test/', [1]),
                $page('https://site.test/about', [2, 3]),
            ], ['uncovered']),
        ];
    }

    public function testPagesAreDeduplicatedAcrossComponents(): void
    {
        $manifest = (new ManifestBuilder())->build($this->samples(), null, self::OPTIONS, '/out', 'full', 'tok');

        $this->assertCount(2, $manifest['pages']);
        $home = $manifest['pages'][0];
        $this->assertSame('https://site.test/', $home['url']);
        $this->assertSame(['cards' => [7], 'hero' => [1]], $home['components']);
        $this->assertSame('p1', $home['id']);
        $this->assertSame(['hero' => [2, 3]], $manifest['pages'][1]['components']);
    }

    public function testComponentSummary(): void
    {
        $manifest = (new ManifestBuilder())->build($this->samples(), null, self::OPTIONS, '/out', 'full', 'tok');

        $this->assertSame(
            ['label' => 'Hero', 'usages' => 9, 'pages' => 9, 'variants' => 2, 'coveredVariants' => 1, 'tested' => 2],
            $manifest['components']['hero'],
        );
    }

    public function testFilter(): void
    {
        $manifest = (new ManifestBuilder())->build($this->samples(), ['cards'], self::OPTIONS, '/out', 'full', 'tok');

        $this->assertSame(['cards'], array_keys($manifest['components']));
        $this->assertCount(1, $manifest['pages']);
        $this->assertSame(['cards' => [7]], $manifest['pages'][0]['components']);
    }

    public function testMarkersOnlyInFullModeWithToken(): void
    {
        $builder = new ManifestBuilder();

        $full = $builder->build($this->samples(), null, self::OPTIONS, '/out', 'full', 'tok');
        $this->assertSame(['header' => 'X-Component-Regression', 'token' => 'tok'], $full['markers']);

        $this->assertNull($builder->build($this->samples(), null, self::OPTIONS, '/out', 'readonly', 'tok')['markers']);
        $this->assertNull($builder->build($this->samples(), null, self::OPTIONS, '/out', 'full', null)['markers']);
    }

    public function testBaseUrlRewrite(): void
    {
        $manifest = (new ManifestBuilder())->build(
            $this->samples(), null, self::OPTIONS, '/out', 'full', null, 'http://web:8080/',
        );

        $this->assertSame('http://web:8080/', $manifest['pages'][0]['url']);
        $this->assertSame('http://web:8080/about', $manifest['pages'][1]['url']);
    }

    public function testRewriteKeepsPathQueryAndFragment(): void
    {
        $this->assertSame('http://ci/nl/a?x=1#f', ManifestBuilder::rewrite('https://site.test/nl/a?x=1#f', 'http://ci'));
        $this->assertSame('https://site.test/a', ManifestBuilder::rewrite('https://site.test/a', ''));
        $this->assertSame('not a url', ManifestBuilder::rewrite('not a url', 'http://ci'));
    }
}
