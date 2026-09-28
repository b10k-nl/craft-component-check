<?php

namespace b10k\componentcheck\services;

use b10k\componentcheck\models\ComponentSample;

/**
 * Turns component samples into the manifest the Playwright runner reads.
 *
 * The manifest is the contract between Craft (which knows *what* to test) and
 * the runner (which knows *how*). It is plain JSON, written to disk, so it can
 * also be produced on one machine and run on another, or read by a custom
 * Playwright suite.
 *
 * Pages are de-duplicated: a page that shows a Hero and Cards is visited once
 * per viewport and checked for both.
 */
final class ManifestBuilder
{
    public const SCHEMA = 1;

    /**
     * @param array<string, ComponentSample> $samples
     * @param string[]|null $only Component handles to include; null = all.
     * @param array{
     *     viewports: array<string, array{width: int, height: int}>,
     *     timeout: int,
     *     concurrency: int,
     *     ignoreErrors: string[],
     *     blockRequests: string[],
     *     ignoreHttpsErrors: bool,
     *     tolerance?: int,
     * } $options
     * @return array<string, mixed>
     */
    public function build(
        array $samples,
        ?array $only,
        array $options,
        string $outputDir,
        string $mode,
        ?string $token,
        string $baseUrl = '',
        ?\DateTimeInterface $now = null,
    ): array {
        if ($only !== null) {
            $samples = array_intersect_key($samples, array_flip($only));
        }

        $components = [];
        /** @var array<string, array{url: string, title: string, site: string, components: array<string, int[]>, blocks: array<int, array{updated: ?string}>}> $pages */
        $pages = [];

        foreach ($samples as $handle => $sample) {
            $components[$handle] = [
                'label' => $sample->label,
                'usages' => $sample->usageCount,
                'pages' => $sample->pageCount,
                'variants' => $sample->variantCount,
                'coveredVariants' => $sample->coveredVariantCount(),
                'tested' => count($sample->pages),
            ];

            foreach ($sample->pages as $page) {
                $url = self::rewrite($page['url'], $baseUrl);
                $pages[$url] ??= [
                    'url' => $url,
                    'title' => $page['title'],
                    'site' => $page['site'],
                    'components' => [],
                    'blocks' => [],
                ];
                $pages[$url]['components'][$handle] = $page['blockIds'];
                foreach ($page['blockIds'] as $id) {
                    $updated = $page['updated'][$id] ?? '';
                    $pages[$url]['blocks'][$id] = ['updated' => $updated === '' ? null : $updated];
                }
            }
        }

        ksort($pages);
        $list = [];
        $i = 0;
        foreach ($pages as $page) {
            ksort($page['components']);
            ksort($page['blocks']);
            $list[] = ['id' => 'p' . (++$i)] + $page;
        }

        $markers = $mode === ActivationPolicy::FULL && $token !== null;

        return [
            'schema' => self::SCHEMA,
            'generatedAt' => ($now ?? new \DateTimeImmutable())->format(DATE_ATOM),
            'mode' => $mode,
            'filter' => $only,
            'markers' => $markers ? [
                'header' => MarkerToken::HEADER,
                'token' => $token,
            ] : null,
            'outputDir' => $outputDir,
            'viewports' => $options['viewports'],
            'options' => [
                'timeout' => $options['timeout'],
                'concurrency' => $options['concurrency'],
                'ignoreErrors' => array_values($options['ignoreErrors']),
                'blockRequests' => array_values($options['blockRequests']),
                'ignoreHttpsErrors' => $options['ignoreHttpsErrors'],
                'tolerance' => $options['tolerance'] ?? 2,
            ],
            'components' => $components,
            'pages' => $list,
        ];
    }

    /**
     * Replaces scheme, host and port of `$url` with those of `$baseUrl`,
     * keeping any path prefix `$baseUrl` has.
     */
    public static function rewrite(string $url, string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $rest = ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        return $baseUrl . ($rest === '' ? '/' : $rest);
    }
}
