<?php

namespace b10k\componentcheck\models;

/**
 * One block (nested entry) of a component type, on one page.
 *
 * Plain data, no Craft objects: discovery produces these, the sampler and the
 * manifest builder consume them, and both of those are unit-tested.
 */
final class Usage
{
    /**
     * @param string $component Entry type handle of the block, e.g. "hero".
     * @param string $componentLabel Entry type name, e.g. "Hero".
     * @param string $field Handle of the Matrix field the block lives in.
     * @param int $blockId Nested entry ID.
     * @param int $pageId ID of the top-level element that has the URL.
     * @param string $pageTitle
     * @param string $url Absolute URL of the page.
     * @param string $site Site handle.
     * @param string $section Section handle of the page ("" if not an entry).
     * @param string $pageType Entry type handle of the page.
     * @param int $depth 1 = directly in the page's Matrix field, 2+ = nested.
     * @param string[] $variant Traits of the block's content: handles of filled
     *        fields, plus `handle=value` for option fields. Sorted.
     */
    public function __construct(
        public readonly string $component,
        public readonly string $componentLabel,
        public readonly string $field,
        public readonly int $blockId,
        public readonly int $pageId,
        public readonly string $pageTitle,
        public readonly string $url,
        public readonly string $site,
        public readonly string $section,
        public readonly string $pageType,
        public readonly int $depth = 1,
        public readonly array $variant = [],
    ) {
    }

    /**
     * The key that decides whether two usages are "the same kind" of usage.
     */
    public function variantKey(): string
    {
        $traits = $this->variant;
        sort($traits);

        return implode('|', [
            $this->section,
            $this->pageType,
            $this->site,
            implode(',', $traits),
        ]);
    }
}
