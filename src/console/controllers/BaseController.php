<?php

namespace b10k\componentcheck\console\controllers;

use b10k\componentcheck\Plugin;
use b10k\componentcheck\services\ActivationPolicy;
use craft\helpers\Console;
use craft\helpers\Json;
use yii\console\Controller;

/**
 * Shared plumbing for the plugin's commands.
 *
 * Exit codes are part of the contract with CI and coding agents:
 *
 * - 0 — everything tested passed
 * - 1 — regressions found
 * - 2 — could not run (mode off, setup problem, unknown component)
 */
abstract class BaseController extends Controller
{
    public const EXIT_PASSED = 0;
    public const EXIT_FAILED = 1;
    public const EXIT_ERROR = 2;

    /**
     * @var bool Print machine-readable JSON to stdout instead of a report.
     */
    public bool $json = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['json']);
    }

    protected function plugin(): Plugin
    {
        return Plugin::getInstance();
    }

    /**
     * Refuses (with exit code 2) when the mode does not permit the command.
     */
    protected function requireMode(string $required): ?int
    {
        $plugin = $this->plugin();
        if (ActivationPolicy::allows($plugin->getMode(), $required)) {
            return null;
        }

        return $this->error(
            "Component Check is not enabled here. " . $plugin->explainMode(),
            ['mode' => $plugin->getMode()],
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function error(string $message, array $extra = []): int
    {
        if ($this->json) {
            $this->writeJson(['status' => 'error', 'error' => $message] + $extra);
        } else {
            $this->stderr($message . "\n", Console::FG_RED);
        }
        return self::EXIT_ERROR;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function writeJson(array $data): void
    {
        $this->stdout(Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * Splits `hero,cards` (or repeated arguments) into a list; empty = null.
     *
     * @return string[]|null
     */
    protected static function list(string $value): ?array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
        return $items === [] ? null : $items;
    }

    protected static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }
}
