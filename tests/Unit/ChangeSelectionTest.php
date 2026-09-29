<?php

namespace b10k\componentcheck\tests\Unit;

use b10k\componentcheck\services\ChangeSelection;
use PHPUnit\Framework\TestCase;

class ChangeSelectionTest extends TestCase
{
    private const KNOWN = ['cardsGrid', 'hero', 'richText'];

    public function testTemplateChangeSelectsItsBlocks(): void
    {
        $s = ChangeSelection::fromImpact(['entryTypes' => ['hero'], 'unmapped' => []], self::KNOWN);

        $this->assertSame('some', $s['mode']);
        $this->assertSame(['hero'], $s['components']);
        $this->assertSame('Changed files affect hero.', $s['reason']);
    }

    public function testCssOrJsMeansEverything(): void
    {
        $s = ChangeSelection::fromImpact(['entryTypes' => ['hero'], 'unmapped' => ['web/dist/app.css']], self::KNOWN);

        $this->assertSame('all', $s['mode']);
        $this->assertSame(self::KNOWN, $s['components']);
        $this->assertStringContainsString('web/dist/app.css', $s['reason']);
    }

    public function testBlocksNotOnAnyPageAreReportedNotDropped(): void
    {
        $s = ChangeSelection::fromImpact(['entryTypes' => ['quote', 'hero']], self::KNOWN);
        $this->assertSame(['hero'], $s['components']);
        $this->assertSame(['quote'], $s['untestable']);
        $this->assertStringContainsString('not on any live page: quote', $s['reason']);

        $none = ChangeSelection::fromImpact(['entryTypes' => ['quote']], self::KNOWN);
        $this->assertSame('none', $none['mode']);
        $this->assertStringContainsString('quote', $none['reason']);
    }

    public function testToolingAndDocsDoNotForceAFullRun(): void
    {
        // What a local dev setup typically has uncommitted.
        $s = ChangeSelection::fromImpact([
            'entryTypes' => ['hero'],
            'unmapped' => ['composer.json', 'composer.lock', 'config/project/project.yaml', 'package.json', '.ddev/config.yaml', 'README.md'],
        ], self::KNOWN);

        $this->assertSame('some', $s['mode']);
        $this->assertSame(['hero'], $s['components']);
        $this->assertCount(6, $s['ignored']);
        $this->assertStringContainsString('Ignored, not front-end: composer.json, composer.lock, config/project/project.yaml +3 more.', $s['reason']);
    }

    public function testWhatCountsAsFrontEnd(): void
    {
        foreach (['web/dist/app.css', 'src/css/site.scss', 'src/js/app.ts', 'modules/Module.php', 'config/general.php', 'web/index.php'] as $f) {
            $this->assertTrue(ChangeSelection::isFrontEndFile($f), $f);
        }
        foreach (['.env', '.github/workflows/ci.yml', 'docs/notes.md', 'yarn.lock', 'storage/logs/web.log', 'vendor/x/y.php', 'config/project/entryTypes/hero.yaml'] as $f) {
            $this->assertFalse(ChangeSelection::isFrontEndFile($f), $f);
        }
    }

    public function testNothingRenderedThroughTheChange(): void
    {
        $s = ChangeSelection::fromImpact(['entryTypes' => [], 'unmapped' => []], self::KNOWN);
        $this->assertSame('none', $s['mode']);
        $this->assertSame('No block is rendered through the changed files.', $s['reason']);
    }
}
