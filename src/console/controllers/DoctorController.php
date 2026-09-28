<?php

namespace b10k\componentcheck\console\controllers;

use b10k\componentcheck\services\ActivationPolicy;
use b10k\componentcheck\services\TestRunner;
use Craft;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\helpers\Json;

/**
 * Checks everything a test run needs, and says how to fix what is missing.
 *
 *     php craft component-check/doctor
 *     php craft component-check/doctor --json
 *
 * Works in every mode, including `off`, so it can explain why nothing runs.
 */
class DoctorController extends BaseController
{
    private const OK = 'ok';
    private const WARN = 'warn';
    private const FAIL = 'fail';

    public function actionIndex(): int
    {
        $checks = [];
        $plugin = $this->plugin();
        $settings = $plugin->getSettings();
        $root = (string)Craft::getAlias('@root');
        $runner = $plugin->getTestRunner();

        // 1. Mode
        $mode = $plugin->getMode();
        $checks[] = $this->check(
            'mode',
            ActivationPolicy::allows($mode, ActivationPolicy::READONLY) ? self::OK : self::FAIL,
            $plugin->explainMode(),
            $mode === ActivationPolicy::OFF ? "Set COMPONENT_CHECK_MODE=full (or readonly) for this environment." : null,
        );

        // 2. Node
        $node = $runner->capture([$settings->nodeBinary, '--version'], $root);
        $version = trim($node['stdout']);
        $major = (int)ltrim(explode('.', $version)[0] ?? '', 'v');
        $checks[] = $node['exitCode'] === 0 && $major >= 18
            ? $this->check('node', self::OK, "Node {$version}")
            : $this->check(
                'node',
                self::FAIL,
                $node['exitCode'] === 0 ? "Node {$version} is too old (18+ needed)" : "Node not found (“{$settings->nodeBinary}”)",
                'Install Node 18+ where `php craft` runs (DDEV: set nodejs_version in .ddev/config.yaml), or set nodeBinary.',
            );

        // 3. Playwright + Chromium (asked of the runner itself, so resolution matches a real run)
        if ($node['exitCode'] === 0) {
            $probe = $runner->capture([$settings->nodeBinary, TestRunner::doctorScript()], $root);
            $info = Json::decodeIfJson(trim($probe['stdout']));
            $info = is_array($info) ? $info : [];

            $checks[] = !empty($info['playwright'])
                ? $this->check('playwright', self::OK, "playwright {$info['playwright']}")
                : $this->check('playwright', self::FAIL, 'The `playwright` package is not installed in this project.', 'npm install --save-dev playwright');

            if (!empty($info['playwright'])) {
                $checks[] = !empty($info['chromium'])
                    ? $this->check('chromium', self::OK, 'Chromium is installed')
                    : $this->check(
                        'chromium',
                        self::FAIL,
                        'Chromium for Playwright is not installed.',
                        'npx playwright install --with-deps chromium',
                    );
            }
        }

        // 4. Output directory
        $out = $runner->outputDir();
        try {
            FileHelper::createDirectory($out);
            $writable = is_writable($out);
        } catch (\Throwable) {
            $writable = false;
        }
        $checks[] = $this->check('output', $writable ? self::OK : self::FAIL, "Artifacts go to {$out}", $writable ? null : 'Make it writable or change outputPath.');

        // 5. Discovery + reachability (only if allowed to query)
        if (ActivationPolicy::allows($mode, ActivationPolicy::READONLY)) {
            ['samples' => $samples, 'discovery' => $discovery] = $runner->sample();

            $checks[] = $samples === []
                ? $this->check('discovery', self::WARN, 'No Matrix blocks found on live pages.', 'Add content, or check the `fields` setting.')
                : $this->check('discovery', self::OK, sprintf(
                    '%d component(s) in use across %d Matrix field(s)',
                    count($samples),
                    count($discovery['fields']),
                ));

            $firstUrl = null;
            if ($samples !== []) {
                $manifest = $runner->manifest($samples, null, null, false);
                $firstUrl = $manifest['pages'][0]['url'] ?? null;
            }
            if ($firstUrl !== null) {
                $checks[] = $this->reachability($firstUrl, $settings->ignoreHttpsErrors);
            }

            $checks[] = $this->markerUsage($mode);
        }

        $failed = count(array_filter($checks, static fn($c) => $c['status'] === self::FAIL));

        if ($this->json) {
            $this->writeJson(['status' => $failed === 0 ? 'ok' : 'error', 'checks' => $checks]);
        } else {
            foreach ($checks as $check) {
                [$symbol, $color] = match ($check['status']) {
                    self::OK => ['✓', Console::FG_GREEN],
                    self::WARN => ['!', Console::FG_YELLOW],
                    default => ['✕', Console::FG_RED],
                };
                $this->stdout("{$symbol} ", $color);
                $this->stdout(sprintf("%-11s %s\n", $check['id'], $check['message']));
                if ($check['fix'] !== null) {
                    $this->stdout("              → {$check['fix']}\n", Console::FG_GREY);
                }
            }
            $this->stdout($failed === 0 ? "\nReady.\n" : "\n{$failed} problem(s) to fix.\n", $failed === 0 ? Console::FG_GREEN : Console::FG_RED);
        }

        return $failed === 0 ? self::EXIT_PASSED : self::EXIT_ERROR;
    }

    /**
     * @return array{id: string, status: string, message: string, fix: ?string}
     */
    private function check(string $id, string $status, string $message, ?string $fix = null): array
    {
        return ['id' => $id, 'status' => $status, 'message' => $message, 'fix' => $fix];
    }

    /**
     * Can this machine reach the site at the URLs the browser will open?
     *
     * @return array{id: string, status: string, message: string, fix: ?string}
     */
    private function reachability(string $url, bool $ignoreHttpsErrors): array
    {
        try {
            $response = Craft::createGuzzleClient([
                'verify' => !$ignoreHttpsErrors,
                'http_errors' => false,
                'timeout' => 10,
                'allow_redirects' => true,
            ])->get($url);
            $status = $response->getStatusCode();

            return $status < 400
                ? $this->check('site', self::OK, "{$url} → HTTP {$status}")
                : $this->check('site', self::FAIL, "{$url} → HTTP {$status}", 'Is the site running at this address from here?');
        } catch (\Throwable $e) {
            return $this->check(
                'site',
                self::FAIL,
                "{$url} is not reachable: " . $e->getMessage(),
                'If the browser must use another address (CI, Docker), set baseUrl in config/component-check.php.',
            );
        }
    }

    /**
     * Are there any marker helpers in the templates at all?
     *
     * @return array{id: string, status: string, message: string, fix: ?string}
     */
    private function markerUsage(string $mode): array
    {
        $templates = Craft::$app->getPath()->getSiteTemplatesPath();
        $found = false;

        if (is_dir($templates)) {
            foreach (FileHelper::findFiles($templates, ['only' => ['*.twig', '*.html']]) as $file) {
                if (str_contains((string)file_get_contents($file), 'craft.componentCheck.')) {
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            return $this->check(
                'markers',
                self::WARN,
                'No craft.componentCheck markers in templates: only page-level checks will run.',
                'Wrap the include in your block loop with {{ craft.componentCheck.start(block) }} … {{ craft.componentCheck.end() }}.',
            );
        }

        return $mode === ActivationPolicy::FULL
            ? $this->check('markers', self::OK, 'Marker helpers found in templates')
            : $this->check('markers', self::WARN, "Marker helpers found, but mode “{$mode}” does not render them: page-level checks only.");
    }
}
