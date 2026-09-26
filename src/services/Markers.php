<?php

namespace b10k\componentregression\services;

use b10k\componentregression\Plugin;
use Craft;
use yii\base\Component;
use craft\base\ElementInterface;
use craft\elements\Entry;

/**
 * Decides, once per request, whether component markers render — and renders
 * them.
 *
 * Markers only appear when all of these hold:
 *
 * 1. the plugin is in `full` mode;
 * 2. it is a site (front-end) web request;
 * 3. the request carries a valid, unexpired {@see MarkerToken}.
 *
 * Everyone else — visitors, crawlers, editors, production — gets exactly the
 * markup they would get without the plugin: the Twig helpers return an empty
 * string. When markers *do* render, the response is marked `no-store` so no
 * shared cache keeps the marked-up copy.
 */
class Markers extends Component
{
    private ?bool $enabled = null;
    private MarkerRenderer $renderer;

    public function init(): void
    {
        parent::init();
        $this->renderer = new MarkerRenderer();
    }

    public function isEnabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        $this->enabled = false;
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
            return false;
        }

        if (Plugin::getInstance()->getMode() !== ActivationPolicy::FULL) {
            return false;
        }

        $token = $request->getHeaders()->get(MarkerToken::HEADER);
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;

        if (!MarkerToken::verify(is_string($token) ? $token : null, $key, time())) {
            return false;
        }

        $headers = Craft::$app->getResponse()->getHeaders();
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('X-Robots-Tag', 'noindex');

        return $this->enabled = true;
    }

    public function start(?ElementInterface $block): string
    {
        if ($block === null || !$this->isEnabled()) {
            return '';
        }
        return $this->renderer->start(self::componentHandle($block), (int)$block->id);
    }

    public function end(): string
    {
        return $this->isEnabled() ? $this->renderer->end() : '';
    }

    public function attributesFor(?ElementInterface $block): string
    {
        if ($block === null || !$this->isEnabled()) {
            return '';
        }
        return $this->renderer->attributes(self::componentHandle($block), (int)$block->id);
    }

    private static function componentHandle(ElementInterface $block): string
    {
        if ($block instanceof Entry) {
            return $block->getType()->handle;
        }
        return $block::refHandle() ?? 'element';
    }
}
