<?php

namespace b10k\componentregression\tests\Unit;

use b10k\componentregression\services\MarkerRenderer;
use PHPUnit\Framework\TestCase;

class MarkerRendererTest extends TestCase
{
    public function testCommentsNest(): void
    {
        $r = new MarkerRenderer();

        $this->assertSame('<!--cr:start cardsGrid 10-->', $r->start('cardsGrid', 10));
        $this->assertSame('<!--cr:start card 11-->', $r->start('card', 11));
        $this->assertSame(2, $r->depth());
        $this->assertSame('<!--cr:end 11-->', $r->end());
        $this->assertSame('<!--cr:end 10-->', $r->end());
    }

    public function testUnbalancedEndIsHarmless(): void
    {
        $this->assertSame('', (new MarkerRenderer())->end());
    }

    public function testHandleCannotBreakOutOfTheComment(): void
    {
        $r = new MarkerRenderer();
        // ">" and "<" are stripped, so the comment cannot be closed early.
        $this->assertSame('<!--cr:start hero--scriptx 1-->', $r->start('hero--><script>x', 1));
        $this->assertSame('<!--cr:start unknown 2-->', $r->start('!!', 2));
    }

    public function testAttributesAreEscapedAndSanitised(): void
    {
        $r = new MarkerRenderer();
        $this->assertSame('data-cr-component="hero" data-cr-block="5"', $r->attributes('hero', 5));
        $this->assertSame('data-cr-component="heroonloadx" data-cr-block="5"', $r->attributes('hero"onload="x', 5));
    }
}
