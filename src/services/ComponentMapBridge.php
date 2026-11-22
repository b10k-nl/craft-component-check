<?php

namespace b10k\componentcheck\services;

use b10k\componentcheck\helpers\Cli;
use b10k\componentcheck\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\Json;

/**
 * Talks to Component Map, if it is installed, to turn changed files into the
 * components worth testing.
 *
 * Deliberately through Component Map's command line and its documented JSON
 * (`component-map/impact --json`), not its PHP classes: the two plugins are
 * released separately, and a JSON contract survives refactors that a class
 * API would not.
 */
class ComponentMapBridge extends Component
{
    public const HANDLE = 'component-map';

    public function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled(self::HANDLE);
    }

    /**
     * The command Component Map's impact runs as. Files (or --git / --since)
     * are appended by the caller.
     *
     * Craft 6: through Artisan (`artisan craft:component-map:impact`), the
     * form the Yii2 adapter registers; `craft component-map/impact` still
     * works but is logged as deprecated in Yii2 Loose Ends.
     *
     * `--json=1`, not `--json`: Yii reads `--json /path/to/file.twig` as the
     * option's value, and impact then sees no files at all.
     *
     * @return string[]
     */
    public function impactCommand(): array
    {
        return [PHP_BINARY, base_path('artisan'), 'craft:' . self::HANDLE . ':impact', '--json=1'];
    }

    /**
     * Runs impact and returns its JSON.
     *
     * @param string[] $args e.g. ['--git'], ['--since=main'], ['templates/_blocks/hero.twig']
     * @return array<string, mixed>
     * @throws \RuntimeException when Component Map is not installed or impact fails.
     */
    public function impact(array $args): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('This needs Component Map: ' . Cli::composer('require b10k/craft-component-map') . ' && ' . Cli::craft('plugin:install component-map'));
        }

        $result = Plugin::getInstance()->getTestRunner()->capture([...$this->impactCommand(), ...$args], (string)Craft::getAlias('@root'));
        $data = Json::decodeIfJson(trim($result['stdout']));

        if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
            $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : 'craft:component-map:impact failed (exit ' . $result['exitCode'] . ')';
            throw new \RuntimeException($message);
        }

        return $data;
    }
}

