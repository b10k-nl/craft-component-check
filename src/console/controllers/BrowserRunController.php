<?php

namespace b10k\componentcheck\console\controllers;

/**
 * Shared by the commands that open a browser (`test`, `snapshot`): parses the
 * component and viewport arguments and builds the manifest.
 */
abstract class BrowserRunController extends BaseController
{
    /**
     * @var string Comma-separated viewport names (default: all configured).
     */
    public string $viewport = '';

    /**
     * @var bool Show the browser window (local debugging).
     */
    public bool $headed = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['viewport', 'headed']);
    }

    /**
     * @return array<string, mixed>|int The manifest, or an exit code after
     *         reporting the problem.
     */
    protected function prepareManifest(string $components, int $tokenTtl = 3600, ?array $samples = null): array|int
    {
        $plugin = $this->plugin();
        $runner = $plugin->getTestRunner();
        $settings = $plugin->getSettings();

        $viewports = self::list($this->viewport);
        if ($viewports !== null && ($unknown = array_diff($viewports, array_keys($settings->viewports))) !== []) {
            return $this->error(
                'Unknown viewport(s): ' . implode(', ', $unknown) . '. Configured: ' . implode(', ', array_keys($settings->viewports)),
            );
        }

        $samples ??= $runner->sample()['samples'];

        $only = self::list($components);
        if ($only !== null && ($unknown = array_diff($only, array_keys($samples))) !== []) {
            return $this->error(
                'No usages found for: ' . implode(', ', $unknown) . '. Known components: ' . implode(', ', array_keys($samples)),
                ['known' => array_keys($samples)],
            );
        }

        $manifest = $runner->manifest($samples, $only, $viewports, true, $tokenTtl);

        if ($manifest['pages'] === []) {
            return $this->error('Nothing to test: no component is used on a live page. Run `php craft component-check/discover`.');
        }

        return $manifest;
    }
}
