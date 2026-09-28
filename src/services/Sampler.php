<?php

namespace b10k\componentcheck\services;

use b10k\componentcheck\models\ComponentSample;
use b10k\componentcheck\models\Usage;

/**
 * Picks representative pages per component.
 *
 * A real site can use a Hero on thousands of pages; testing all of them is slow
 * and mostly redundant. Usages are grouped into *variants* (same section, page
 * type, site and the same set of filled-in fields — see
 * {@see Usage::variantKey()}), and pages are chosen greedily so that every
 * variant is covered `$perVariant` times with as few pages as possible: a page
 * that shows two different variants of a component counts for both.
 *
 * Deterministic: the same content always yields the same pages, in the same
 * order, so results are comparable between runs and between machines.
 */
final class Sampler
{
    /**
     * @param Usage[] $usages
     * @return array<string, ComponentSample> Keyed by component handle, sorted.
     */
    public function sample(array $usages, int $perVariant = 1, int $maxPerComponent = 10): array
    {
        $perVariant = max(1, $perVariant);
        $maxPerComponent = max(1, $maxPerComponent);

        /** @var array<string, Usage[]> $byComponent */
        $byComponent = [];
        foreach ($usages as $usage) {
            $byComponent[$usage->component][] = $usage;
        }
        ksort($byComponent);

        $samples = [];
        foreach ($byComponent as $component => $componentUsages) {
            $samples[$component] = $this->sampleComponent($component, $componentUsages, $perVariant, $maxPerComponent);
        }

        return $samples;
    }

    /**
     * @param Usage[] $usages
     */
    private function sampleComponent(string $component, array $usages, int $perVariant, int $maxPages): ComponentSample
    {
        /** @var array<string, Usage[]> $byUrl */
        $byUrl = [];
        /** @var array<string, array<string, true>> $variantsByUrl */
        $variantsByUrl = [];
        foreach ($usages as $usage) {
            $byUrl[$usage->url][] = $usage;
            $variantsByUrl[$usage->url][$usage->variantKey()] = true;
        }

        $allVariants = [];
        foreach ($variantsByUrl as $variants) {
            foreach ($variants as $key => $_) {
                $allVariants[$key] = true;
            }
        }

        // How many more pages each variant still needs.
        $need = array_fill_keys(array_keys($allVariants), $perVariant);
        $selected = [];

        while (count($selected) < $maxPages) {
            $bestUrl = null;
            $bestGain = 0;

            foreach ($variantsByUrl as $url => $variants) {
                if (isset($selected[$url])) {
                    continue;
                }
                $gain = 0;
                foreach ($variants as $key => $_) {
                    if ($need[$key] > 0) {
                        $gain++;
                    }
                }
                if ($gain > $bestGain || ($gain === $bestGain && $gain > 0 && self::urlBefore($url, (string)$bestUrl))) {
                    $bestUrl = (string)$url;
                    $bestGain = $gain;
                }
            }

            if ($bestUrl === null) {
                break;
            }

            $selected[$bestUrl] = true;
            foreach ($variantsByUrl[$bestUrl] as $key => $_) {
                if ($need[$key] > 0) {
                    $need[$key]--;
                }
            }
        }

        $covered = [];
        $pages = [];
        foreach (array_keys($selected) as $url) {
            $pageUsages = $byUrl[$url];
            $first = $pageUsages[0];
            $blockIds = array_values(array_unique(array_map(static fn(Usage $u) => $u->blockId, $pageUsages)));
            sort($blockIds);
            $variants = array_keys($variantsByUrl[$url]);
            sort($variants);
            foreach ($variants as $key) {
                $covered[$key] = true;
            }
            $pages[] = [
                'url' => (string)$url,
                'title' => $first->pageTitle,
                'pageId' => $first->pageId,
                'site' => $first->site,
                'blockIds' => $blockIds,
                'variants' => $variants,
            ];
        }

        usort($pages, static fn(array $a, array $b) => self::urlBefore($a['url'], $b['url']) ? -1 : 1);

        $uncovered = array_values(array_diff(array_keys($allVariants), array_keys($covered)));
        sort($uncovered);

        return new ComponentSample(
            component: $component,
            label: $usages[0]->componentLabel,
            usageCount: count($usages),
            pageCount: count($byUrl),
            variantCount: count($allVariants),
            pages: $pages,
            uncoveredVariants: $uncovered,
        );
    }

    /**
     * Shorter URLs first (the homepage before deep pages), then alphabetical.
     */
    private static function urlBefore(string $a, string $b): bool
    {
        if ($b === '') {
            return true;
        }
        return [strlen($a), $a] < [strlen($b), $b];
    }
}
