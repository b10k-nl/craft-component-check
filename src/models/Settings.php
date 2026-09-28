<?php

namespace b10k\componentcheck\models;

use b10k\componentcheck\services\ActivationPolicy;
use craft\base\Model;

/**
 * Component Check settings.
 *
 * Everything here is meant to live in `config/component-check.php`
 * (committed, env-aware) rather than in the database: the plugin has no CP
 * settings screen in v0.1.
 */
class Settings extends Model
{
    /**
     * @var string `''` (auto), `off`, `readonly` or `full`.
     *
     * Auto means: `full` when admin changes are allowed (development), `off`
     * otherwise (production, and usually staging). Set it explicitly — typically
     * from an env var — to run on staging or in CI. See {@see ActivationPolicy}.
     */
    public string $mode = '';

    /**
     * @var string Replaces the scheme + host (+ port) of every discovered URL.
     * Useful when the browser reaches the site under a different address than
     * the one Craft generates (CI service containers, Docker networks). Empty =
     * use the URLs exactly as Craft generates them.
     */
    public string $baseUrl = '';

    /**
     * @var string[] Matrix field handles to scan. Empty = every Matrix field.
     */
    public array $fields = [];

    /**
     * @var int How many pages to test per component variant. A "variant" is a
     * distinct combination of section, entry type, site and which of the
     * block's fields are filled in.
     */
    public int $samplesPerVariant = 1;

    /**
     * @var int Upper bound on pages tested per component, whatever the number of
     * variants.
     */
    public int $maxPagesPerComponent = 10;

    /**
     * @var array<string, array{width: int, height: int}> Named viewports.
     */
    public array $viewports = [
        'desktop' => ['width' => 1440, 'height' => 900],
        'mobile' => ['width' => 390, 'height' => 844],
    ];

    /**
     * @var string[] Console errors / page errors containing any of these
     * substrings are ignored (third-party noise).
     */
    public array $ignoreErrors = [];

    /**
     * @var string[] Requests whose URL contains any of these substrings are
     * aborted before they leave the browser (analytics, chat widgets, ads).
     */
    public array $blockRequests = [
        'googletagmanager.com',
        'google-analytics.com',
        'connect.facebook.net',
        'hotjar.com',
    ];

    /**
     * @var string Where manifests, results, screenshots and traces are written.
     */
    public string $outputPath = '@storage/component-check';

    /**
     * @var string Node binary used to run the bundled Playwright runner.
     */
    public string $nodeBinary = 'node';

    /**
     * @var int Navigation timeout per page, in milliseconds.
     */
    public int $timeout = 30000;

    /**
     * @var int Pages tested in parallel.
     */
    public int $concurrency = 4;

    /**
     * @var int Pixels an element may move or resize before a comparison
     * against a snapshot reports it (absorbs sub-pixel rendering noise).
     */
    public int $tolerance = 2;

    /**
     * @var string[] What `watch` watches: directories or aliases. Only .twig,
     * .html, .css, .js, .mjs, .svg and .json files count.
     */
    public array $watchPaths = ['@templates', '@webroot'];

    /**
     * @var string[] Folder names `watch` skips wherever they appear.
     */
    public array $watchIgnore = ['cpresources', 'node_modules', 'uploads', 'assets'];

    /**
     * @var int How often `watch` looks for changed files, in milliseconds.
     * It polls rather than relying on file events, which do not reliably
     * cross Docker mounts.
     */
    public int $watchInterval = 700;

    /**
     * @var bool Accept self-signed certificates (DDEV, Valet, Herd).
     */
    public bool $ignoreHttpsErrors = true;

    public function init(): void
    {
        parent::init();
        $this->fields = self::stringList($this->fields);
        $this->ignoreErrors = self::stringList($this->ignoreErrors);
        $this->blockRequests = self::stringList($this->blockRequests);
        $this->watchPaths = self::stringList($this->watchPaths);
        $this->watchIgnore = self::stringList($this->watchIgnore);
    }

    public function rules(): array
    {
        return [
            ['mode', 'in', 'range' => ['', ...ActivationPolicy::MODES]],
            [['samplesPerVariant', 'maxPagesPerComponent', 'concurrency'], 'integer', 'min' => 1],
            ['timeout', 'integer', 'min' => 1000],
            ['tolerance', 'integer', 'min' => 0],
            ['watchInterval', 'integer', 'min' => 200],
            ['ignoreHttpsErrors', 'boolean'],
            [['baseUrl', 'outputPath', 'nodeBinary'], 'trim'],
            ['viewports', 'validateViewports'],
        ];
    }

    public function validateViewports(string $attribute): void
    {
        $viewports = $this->$attribute;
        if (!is_array($viewports) || $viewports === []) {
            $this->addError($attribute, 'At least one viewport is required.');
            return;
        }
        foreach ($viewports as $name => $size) {
            if (!is_string($name) || preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
                $this->addError($attribute, 'Viewport names must be lowercase letters, digits and dashes.');
                return;
            }
            if (!is_array($size) || (int)($size['width'] ?? 0) < 1 || (int)($size['height'] ?? 0) < 1) {
                $this->addError($attribute, "Viewport “{$name}” needs a positive width and height.");
                return;
            }
        }
    }

    /**
     * @return string[]
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn($v) => is_string($v) ? trim($v) : '', $value),
            static fn($v) => $v !== '',
        ));
    }
}
