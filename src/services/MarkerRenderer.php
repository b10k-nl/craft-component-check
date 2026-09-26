<?php

namespace b10k\componentregression\services;

/**
 * Formats the markers that tell the browser where a component starts and ends.
 *
 * Two styles, pick whichever fits the template:
 *
 * - Comments, for dispatchers — wrap the include, touch no component:
 *
 *       {{ craft.regression.start(block) }}
 *       {% include '_blocks/' ~ block.type.handle %}
 *       {{ craft.regression.end() }}
 *
 *   renders `<!--cr:start hero 123-->…<!--cr:end 123-->`. The runner treats
 *   every element between the two comments as the component.
 *
 * - Attributes, on the component's root element:
 *
 *       <section {{ craft.regression.attributes(block) }}>
 *
 *   renders `data-cr-component="hero" data-cr-block="123"`.
 *
 * Comments nest (a card block inside a cards-grid block): `end()` closes the
 * most recent `start()`, so it never needs an argument.
 *
 * Craft-free; whether markers render at all is decided by {@see Markers}.
 */
final class MarkerRenderer
{
    /** @var int[] */
    private array $stack = [];

    public function start(string $component, int $blockId): string
    {
        $this->stack[] = $blockId;
        return sprintf('<!--cr:start %s %d-->', self::handle($component), $blockId);
    }

    public function end(): string
    {
        $blockId = array_pop($this->stack);
        return $blockId === null ? '' : sprintf('<!--cr:end %d-->', $blockId);
    }

    public function attributes(string $component, int $blockId): string
    {
        return sprintf(
            'data-cr-component="%s" data-cr-block="%d"',
            htmlspecialchars(self::handle($component), ENT_QUOTES),
            $blockId,
        );
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    /**
     * Keeps the comment parseable and incapable of closing itself early.
     */
    private static function handle(string $component): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $component);
        return $clean === null || $clean === '' ? 'unknown' : $clean;
    }
}
