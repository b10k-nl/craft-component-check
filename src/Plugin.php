<?php

namespace b10k\componentcheck;

use b10k\componentcheck\models\Settings;
use b10k\componentcheck\services\ActivationPolicy;
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
