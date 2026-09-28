<?php

namespace b10k\componentcheck\tests\Unit;

use b10k\componentcheck\services\MarkerRenderer;
use PHPUnit\Framework\TestCase;

class MarkerRendererTest extends TestCase
{
    public function testCommentsNest(): void
    {
        $r = new MarkerRenderer();

        $this->assertSame('<!--cc:start cardsGrid 10-->', $r->start('cardsGrid', 10));
        $this->assertSame('<!--cc:start card 11-->', $r->start('card', 11));
        $this->assertSame(2, $r->depth());
        $this->assertSame('<!--cc:end 11-->', $r->end());
        $this->assertSame('<!--cc:end 10-->', $r->end());
    }

    public function testUnbalancedEndIsHarmless(): void
    {
        $this->assertSame('', (new MarkerRenderer())->end());
    }

    public function testHandleCannotBreakOutOfTheComment(): void
    {
        $r = new MarkerRenderer();
        // ">" and "<" are stripped, so the comment cannot be closed early.
        $this->assertSame('<!--cc:start hero--scriptx 1-->', $r->start('hero--><script>x', 1));
        $this->assertSame('<!--cc:start unknown 2-->', $r->start('!!', 2));
    }

    public function testAttributesAreEscapedAndSanitised(): void
    {
        $r = new MarkerRenderer();
        $this->assertSame('data-cc-component="hero" data-cc-block="5"', $r->attributes('hero', 5));
        $this->assertSame('data-cc-component="heroonloadx" data-cc-block="5"', $r->attributes('hero"onload="x', 5));
    }
}
