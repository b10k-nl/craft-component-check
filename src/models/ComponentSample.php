<?php

namespace b10k\componentregression\models;

/**
 * The pages chosen to represent one component, and how much of its real-world
 * usage they cover.
 */
final class ComponentSample
{
    /**
     * @param array<int, array{url: string, title: string, pageId: int, site: string, blockIds: int[], variants: string[]}> $pages
     * @param string[] $uncoveredVariants Variant keys no selected page contains
     *        (only non-empty when `maxPagesPerComponent` cut the selection short).
     */
    public function __construct(
        public readonly string $component,
        public readonly string $label,
        public readonly int $usageCount,
        public readonly int $pageCount,
        public readonly int $variantCount,
        public readonly array $pages,
        public readonly array $uncoveredVariants = [],
    ) {
    }

    public function coveredVariantCount(): int
    {
        return $this->variantCount - count($this->uncoveredVariants);
    }
}
