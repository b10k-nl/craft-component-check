<?php

namespace b10k\componentcheck\services;

use b10k\componentcheck\models\Usage;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\elements\Entry;
use craft\fields\BaseOptionsField;
use craft\fields\data\SingleOptionFieldData;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\helpers\Db;

/**
 * Finds every block of every Matrix field, and the page it appears on.
 *
 * In Craft 5 a Matrix block is a nested entry. For each one we walk up the
 * owner chain (blocks can be nested in blocks) to the element that actually has
 * a URL, and keep the usage only if that page is live: enabled, for this site,
 * not a draft or a revision.
 *
 * This is the only part of discovery that touches Craft. What it returns is
 * plain {@see Usage} data for the Craft-free {@see Sampler}.
 */
class UsageDiscovery extends Component
{
    public const SKIP_NO_URL = 'owner has no URL';
    public const SKIP_NOT_LIVE = 'page is not live';
    public const SKIP_DRAFT = 'page is a draft or revision';
    public const SKIP_NO_OWNER = 'owner not found';

    /** @var array<string, ElementInterface|null> */
    private array $ownerCache = [];

    /**
     * @param string[] $fieldHandles Empty = every Matrix field.
     * @return array{
     *     usages: Usage[],
     *     unused: array<int, array{component: string, label: string, field: string}>,
     *     skipped: array<string, int>,
     *     fields: string[],
     * }
     */
    public function discover(array $fieldHandles = []): array
    {
        $usages = [];
        $skipped = [];
        $seenTypes = [];
        $allTypes = [];
        $scannedFields = [];

        foreach ($this->matrixFields($fieldHandles) as $field) {
            $scannedFields[] = $field->handle;

            foreach ($field->getEntryTypes() as $entryType) {
                $allTypes[$entryType->handle] ??= [
                    'component' => $entryType->handle,
                    'label' => $entryType->name,
                    'field' => $field->handle,
                ];
            }

            $query = Entry::find()
                ->fieldId($field->id)
                ->site('*')
                ->orderBy(['elements.id' => SORT_ASC]);

            foreach (Db::each($query) as $block) {
                /** @var Entry $block */
                $result = $this->usageFor($block, $field);

                if (is_string($result)) {
                    $skipped[$result] = ($skipped[$result] ?? 0) + 1;
                    continue;
                }

                $usages[] = $result;
                $seenTypes[$result->component] = true;
            }
        }

        $unused = array_values(array_diff_key($allTypes, $seenTypes));
        usort($unused, static fn(array $a, array $b) => $a['component'] <=> $b['component']);
        ksort($skipped);

        return [
            'usages' => $usages,
            'unused' => $unused,
            'skipped' => $skipped,
            'fields' => $scannedFields,
        ];
    }

    /**
     * @param string[] $handles
     * @return Matrix[]
     */
    private function matrixFields(array $handles): array
    {
        $fields = array_filter(
            Craft::$app->getFields()->getAllFields(),
            static fn(FieldInterface $f) => $f instanceof Matrix,
        );

        if ($handles !== []) {
            $fields = array_filter($fields, static fn(Matrix $f) => in_array($f->handle, $handles, true));
        }

        $fields = array_values($fields);
        usort($fields, static fn(Matrix $a, Matrix $b) => $a->handle <=> $b->handle);

        return $fields;
    }

    /**
     * @return Usage|string A usage, or the reason it was skipped.
     */
    private function usageFor(Entry $block, Matrix $field): Usage|string
    {
        $depth = 1;
        $page = $this->owner($block);

        while ($page instanceof NestedElementInterface && $page->getOwnerId() !== null) {
            $depth++;
            $page = $this->owner($page);
        }

        if ($page === null) {
            return self::SKIP_NO_OWNER;
        }
        if ($page->getIsDraft() || $page->getIsRevision()) {
            return self::SKIP_DRAFT;
        }
        if (!$this->isLive($page)) {
            return self::SKIP_NOT_LIVE;
        }

        $url = $page->getUrl();
        if ($url === null || $url === '') {
            return self::SKIP_NO_URL;
        }

        $type = $block->getType();

        return new Usage(
            component: $type->handle,
            componentLabel: $type->name,
            field: $field->handle,
            blockId: (int)$block->id,
            pageId: (int)$page->id,
            pageTitle: (string)($page->title ?? $url),
            url: $url,
            site: $page->getSite()->handle,
            section: $page instanceof Entry ? ($page->getSection()->handle ?? '') : $page::refHandle() ?? '',
            pageType: $page instanceof Entry ? $page->getType()->handle : '',
            depth: $depth,
            variant: $this->variant($block),
            blockUpdated: $block->dateUpdated?->format(DATE_ATOM) ?? '',
        );
    }

    private function owner(NestedElementInterface $element): ?ElementInterface
    {
        $key = $element->getOwnerId() . ':' . $element->siteId;

        if (!array_key_exists($key, $this->ownerCache)) {
            $this->ownerCache[$key] = $element->getOwner();
        }

        return $this->ownerCache[$key];
    }

    private function isLive(ElementInterface $page): bool
    {
        if (!$page->enabled || !$page->getEnabledForSite()) {
            return false;
        }

        return !($page instanceof Entry) || $page->getStatus() === Entry::STATUS_LIVE;
    }

    /**
     * What kind of Hero this is: which fields are filled in, and the value of
     * option fields (theme = dark, layout = imageRight) — the things a
     * template usually branches on.
     *
     * @return string[]
     */
    private function variant(Entry $block): array
    {
        $traits = [];
        $layout = $block->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        foreach ($layout->getCustomFields() as $field) {
            try {
                $value = $block->getFieldValue($field->handle);

                if ($field instanceof Lightswitch) {
                    $traits[] = $field->handle . '=' . ($value ? 'on' : 'off');
                    continue;
                }

                if ($field instanceof BaseOptionsField && $value instanceof SingleOptionFieldData) {
                    $selected = (string)$value->value;
                    $traits[] = $field->handle . '=' . ($selected === '' ? '∅' : $selected);
                    continue;
                }

                if (!$field->isValueEmpty($value, $block)) {
                    $traits[] = $field->handle;
                }
            } catch (\Throwable $e) {
                Craft::warning("Could not read {$field->handle} on block {$block->id}: {$e->getMessage()}", __METHOD__);
            }
        }

        sort($traits);
        return $traits;
    }
}
