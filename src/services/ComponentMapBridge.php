<?php

namespace b10k\componentcheck\services;

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
     * @return string[]
     */
    public function impactCommand(): array
    {
        return [PHP_BINARY, (string)Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'craft', self::HANDLE . '/impact', '--json'];
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
            throw new \RuntimeException('This needs Component Map: composer require b10k/craft-component-map && php craft plugin/install component-map');
        }

        $result = Plugin::getInstance()->getTestRunner()->capture([...$this->impactCommand(), ...$args], (string)Craft::getAlias('@root'));
        $data = Json::decodeIfJson(trim($result['stdout']));

        if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
            $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : 'component-map/impact failed (exit ' . $result['exitCode'] . ')';
            throw new \RuntimeException($message);
        }

        return $data;
    }
}

