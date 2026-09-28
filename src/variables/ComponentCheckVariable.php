<?php

namespace b10k\componentcheck\variables;

use b10k\componentcheck\Plugin;
use craft\base\ElementInterface;
use Twig\Markup;

/**
 * `craft.componentCheck` in Twig.
 *
 * Every method is safe to call in production: outside a signed test request
 * each one returns an empty string, so templates need no `{% if %}` around
 * them and the plugin can be installed on every environment.
 */
class ComponentCheckVariable
{
    /**
     * Opens a component region. Pair with {@see end()}.
     */
    public function start(?ElementInterface $block = null): Markup
    {
        return self::markup(Plugin::getInstance()->getMarkers()->start($block));
    }

    /**
     * Closes the most recently opened region.
     */
    public function end(): Markup
    {
        return self::markup(Plugin::getInstance()->getMarkers()->end());
    }

    /**
     * Marker attributes for a component's root element.
     */
    public function attributes(?ElementInterface $block = null): Markup
    {
        return self::markup(Plugin::getInstance()->getMarkers()->attributesFor($block));
    }

    /**
     * Whether this request comes from a test run (markers on).
     */
    public function isActive(): bool
    {
        return Plugin::getInstance()->getMarkers()->isEnabled();
    }

    private static function markup(string $html): Markup
    {
        return new Markup($html, 'UTF-8');
    }
}
