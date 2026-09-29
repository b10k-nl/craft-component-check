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

    public function testNothingRenderedThroughTheChange(): void
    {
        $s = ChangeSelection::fromImpact(['entryTypes' => [], 'unmapped' => []], self::KNOWN);
        $this->assertSame('none', $s['mode']);
        $this->assertSame('No block is rendered through the changed files.', $s['reason']);
    }
}
