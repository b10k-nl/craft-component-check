<?php

namespace b10k\componentcheck\tests\Unit;

use b10k\componentcheck\models\Usage;
use b10k\componentcheck\services\Sampler;
use PHPUnit\Framework\TestCase;

class SamplerTest extends TestCase
{
    private static int $blockId = 100;

    /**
     * @param string[] $variant
     */
    private function usage(
        string $component,
        string $url,
        array $variant = [],
        string $section = 'pages',
        string $site = 'default',
    ): Usage {
        return new Usage(
            component: $component,
            componentLabel: ucfirst($component),
            field: 'contentBlocks',
            blockId: ++self::$blockId,
            pageId: crc32($url),
            pageTitle: 'Title ' . $url,
            url: $url,
            site: $site,
            section: $section,
            pageType: 'default',
            variant: $variant,
        );
    }

    public function testOnePagePerVariant(): void
    {
        $usages = [];
        // 50 pages with an identical Hero: one page is enough.
        for ($i = 1; $i <= 50; $i++) {
            $usages[] = $this->usage('hero', "https://site.test/page-{$i}", ['heading', 'image']);
        }

        $samples = (new Sampler())->sample($usages);

        $this->assertSame(50, $samples['hero']->usageCount);
        $this->assertSame(50, $samples['hero']->pageCount);
        $this->assertSame(1, $samples['hero']->variantCount);
        $this->assertCount(1, $samples['hero']->pages);
        // Deterministic and shortest-first: page-1 before page-10.
        $this->assertSame('https://site.test/page-1', $samples['hero']->pages[0]['url']);
    }

    public function testEveryVariantCovered(): void
    {
        $usages = [
            $this->usage('hero', 'https://site.test/a', ['heading', 'image']),
            $this->usage('hero', 'https://site.test/b', ['heading', 'image']),
            $this->usage('hero', 'https://site.test/c', ['heading', 'video']),
            $this->usage('hero', 'https://site.test/news/x', ['heading', 'image'], section: 'news'),
            $this->usage('hero', 'https://site.test/nl/a', ['heading', 'image'], site: 'nl'),
        ];

        $sample = (new Sampler())->sample($usages)['hero'];

        $this->assertSame(4, $sample->variantCount);
        $this->assertCount(4, $sample->pages);
        $this->assertSame([], $sample->uncoveredVariants);
        $urls = array_column($sample->pages, 'url');
        $this->assertNotContains('https://site.test/b', $urls, 'b duplicates a');
    }

    public function testPageWithSeveralVariantsCountsForAll(): void
    {
        $usages = [
            $this->usage('cta', 'https://site.test/x', ['button']),
            $this->usage('cta', 'https://site.test/y', ['text']),
            // /both shows both variants: picking it alone covers everything.
            $this->usage('cta', 'https://site.test/both', ['button']),
            $this->usage('cta', 'https://site.test/both', ['text']),
        ];

        $sample = (new Sampler())->sample($usages)['cta'];

        $this->assertSame(['https://site.test/both'], array_column($sample->pages, 'url'));
        $this->assertCount(2, $sample->pages[0]['blockIds']);
    }

    public function testCapReportsUncoveredVariants(): void
    {
        $usages = [];
        foreach (range(1, 5) as $i) {
            $usages[] = $this->usage('card', "https://site.test/{$i}", ["trait{$i}"]);
        }

        $sample = (new Sampler())->sample($usages, 1, 2)['card'];

        $this->assertCount(2, $sample->pages);
        $this->assertCount(3, $sample->uncoveredVariants);
        $this->assertSame(2, $sample->coveredVariantCount());
    }

    public function testSamplesPerVariant(): void
    {
        $usages = [];
        foreach (range(1, 5) as $i) {
            $usages[] = $this->usage('quote', "https://site.test/{$i}", ['text']);
        }

        $sample = (new Sampler())->sample($usages, 3, 10)['quote'];

        $this->assertCount(3, $sample->pages);
    }

    public function testComponentsSortedAndIndependent(): void
    {
        $samples = (new Sampler())->sample([
            $this->usage('quote', 'https://site.test/q'),
            $this->usage('hero', 'https://site.test/h'),
        ]);

        $this->assertSame(['hero', 'quote'], array_keys($samples));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], (new Sampler())->sample([]));
    }
}
