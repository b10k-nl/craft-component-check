<?php

namespace b10k\componentcheck;

use b10k\componentcheck\mcp\CoverageCheck;
use b10k\componentcheck\mcp\LastRun;
use b10k\componentcheck\mcp\McpBrief;
use b10k\componentcheck\mcp\McpCompat;
use b10k\componentcheck\mcp\Tools;
use b10k\componentcheck\models\Settings;
use b10k\componentcheck\services\ActivationPolicy;
use b10k\componentcheck\services\ComponentMapBridge;
use b10k\componentcheck\services\ManifestBuilder;
use b10k\componentcheck\services\Markers;
use b10k\componentcheck\services\Sampler;
use b10k\componentcheck\services\TestRunner;
use b10k\componentcheck\services\UsageDiscovery;
use b10k\componentcheck\variables\ComponentCheckVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\helpers\App;
use craft\web\twig\variables\CraftVariable;
use yii\base\Event;

/**
 * Component Check — Craft tells Playwright what is worth testing.
 *
 * Installs on every environment (so project config and templates are the same
 * everywhere) and does nothing unless the mode allows it; see
 * {@see ActivationPolicy}.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Env var read when `mode` is not set in config/component-check.php. */
    public const MODE_ENV = 'COMPONENT_CHECK_MODE';

    public string $schemaVersion = '0.1.0';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'discovery' => UsageDiscovery::class,
                'sampler' => Sampler::class,
                'manifestBuilder' => ManifestBuilder::class,
                'markers' => Markers::class,
                'testRunner' => TestRunner::class,
                'componentMap' => ComponentMapBridge::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'b10k\\componentcheck\\console\\controllers';
        }

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('componentCheck', ComponentCheckVariable::class);
            },
        );

        $this->registerMcp();
    }

    /**
     * Craft's MCP server (Craft 5.12+): the tools `component-check.results`,
     * `.usages` and `.screenshot`, the coverage check in
     * `content-model.audit`, and a word in `info.get` on the last run. All read
     * only. Nothing on an older Craft — the classes are only named, never
     * loaded — nothing where the mode is `off`, and nothing on an ordinary
     * request: the handlers run when an MCP server is built.
     *
     * Whatever goes wrong here is logged and left out: Component Check must
     * not be the reason a site's MCP server stops answering.
     */
    private function registerMcp(): void
    {
        if (!class_exists(\craft\services\Mcp::class)) {
            return;
        }

        // Checks have no event of their own; capability types are collected
        // once per server, before any tool can run — the audit included.
        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES,
            function ($event): void {
                // Untyped: a handler whose type no longer matches would throw
                // inside Craft, not here.
                if (!$event instanceof \craft\events\RegisterComponentTypesEvent) {
                    return;
                }
                try {
                    if ($this->getMode() === ActivationPolicy::OFF) {
                        return;
                    }
                    if (McpCompat::toolsFit()) {
                        $event->types[] = Tools::class;
                    }
                    if (!McpCompat::checkFits()) {
                        return;
                    }
                    $checks = Craft::$app->get('mcp')->getCheckRegistry();
                    if (!in_array(McpBrief::CHECK_ID, $checks->ids(), true)) {
                        $checks->register(CoverageCheck::class);
                    }
                } catch (\Throwable $e) {
                    Craft::warning('Component Check could not add itself to Craft’s MCP server: ' . $e->getMessage(), 'component-check');
                }
            },
        );

        // Full instructions in info.get go to control-panel users only; the
        // instructions every client gets are left alone.
        Event::on(
            \craft\mcp\AdminInstructions::class,
            \craft\mcp\AdminInstructions::EVENT_COLLECT_INSTRUCTIONS,
            function ($event): void {
                if (!$event instanceof \craft\mcp\events\CollectingAdminInstructions) {
                    return;
                }
                try {
                    $mode = $this->getMode();
                    if ($mode === ActivationPolicy::OFF) {
                        return;
                    }
                    $event->instructions[] = McpBrief::text(
                        ['mode' => $mode, 'explain' => $this->explainMode()],
                        LastRun::summary(),
                        LastRun::snapshot(),
                        McpCompat::toolsFit(),
                        McpCompat::checkFits(),
                    );
                } catch (\Throwable $e) {
                    Craft::warning('Component Check could not add its MCP instructions: ' . $e->getMessage(), 'component-check');
                }
            },
        );
    }

    /**
     * The effective mode for this environment: `off`, `readonly` or `full`.
     */
    public function getMode(): string
    {
        return ActivationPolicy::resolve($this->configuredMode(), $this->allowAdminChanges());
    }

    public function explainMode(): string
    {
        return ActivationPolicy::explain($this->configuredMode(), $this->allowAdminChanges());
    }

    public function getDiscovery(): UsageDiscovery
    {
        /** @var UsageDiscovery $service */
        $service = $this->get('discovery');
        return $service;
    }

    public function getSampler(): Sampler
    {
        /** @var Sampler $service */
        $service = $this->get('sampler');
        return $service;
    }

    public function getManifestBuilder(): ManifestBuilder
    {
        /** @var ManifestBuilder $service */
        $service = $this->get('manifestBuilder');
        return $service;
    }

    public function getMarkers(): Markers
    {
        /** @var Markers $service */
        $service = $this->get('markers');
        return $service;
    }

    public function getTestRunner(): TestRunner
    {
        /** @var TestRunner $service */
        $service = $this->get('testRunner');
        return $service;
    }

    /**
     * Component Map, when installed: which components a change affects.
     */
    public function getComponentMap(): ComponentMapBridge
    {
        /** @var ComponentMapBridge $service */
        $service = $this->get('componentMap');
        return $service;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function configuredMode(): string
    {
        $mode = $this->getSettings()->mode;
        if ($mode !== '') {
            return $mode;
        }
        $env = App::env(self::MODE_ENV);
        return is_string($env) ? $env : '';
    }

    private function allowAdminChanges(): bool
    {
        return (bool)Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }
}
