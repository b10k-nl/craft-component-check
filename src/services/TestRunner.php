<?php

namespace b10k\componentcheck\services;

use b10k\componentcheck\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\Json;

/**
 * Orchestrates a run: discover → sample → manifest → Node runner → results.
 *
 * Kept out of the console controller so a future control-panel utility, or a
 * queue job, can start the same run.
 */
class TestRunner extends Component
{
    public const MANIFEST_FILE = 'manifest.json';
    public const RESULTS_FILE = 'results.json';

    /**
     * Discovers and samples the project.
     *
     * @return array{samples: array<string, \b10k\componentcheck\models\ComponentSample>, discovery: array<string, mixed>}
     */
    public function sample(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $discovery = $plugin->getDiscovery()->discover($settings->fields);
        $samples = $plugin->getSampler()->sample(
            $discovery['usages'],
            $settings->samplesPerVariant,
            $settings->maxPagesPerComponent,
        );

        return ['samples' => $samples, 'discovery' => $discovery];
    }

    /**
     * @param array<string, \b10k\componentcheck\models\ComponentSample> $samples
     * @param string[]|null $only
     * @param string[]|null $viewports Names from settings; null = all.
     * @return array<string, mixed>
     */
    public function manifest(array $samples, ?array $only, ?array $viewports, bool $withToken): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $mode = $plugin->getMode();

        $allViewports = $settings->viewports;
        if ($viewports !== null) {
            $allViewports = array_intersect_key($allViewports, array_flip($viewports));
        }

        $token = null;
        if ($withToken && $mode === ActivationPolicy::FULL) {
            $key = Craft::$app->getConfig()->getGeneral()->securityKey;
            $token = $key !== '' ? MarkerToken::create($key, time(), 3600) : null;
        }

        return $plugin->getManifestBuilder()->build(
            samples: $samples,
            only: $only,
            options: [
                'viewports' => $allViewports,
                'timeout' => $settings->timeout,
                'concurrency' => $settings->concurrency,
                'ignoreErrors' => $settings->ignoreErrors,
                'blockRequests' => $settings->blockRequests,
                'ignoreHttpsErrors' => $settings->ignoreHttpsErrors,
            ],
            outputDir: $this->outputDir(),
            mode: $mode,
            token: $token,
            baseUrl: $settings->baseUrl,
        );
    }

    /**
     * Writes the manifest, runs the bundled Playwright runner and reads back
     * its results. Never throws for runner problems: they come back as
     * `results.error`, so the caller can report them like any other failure.
     *
     * @param array<string, mixed> $manifest
     * @return array{results: array<string, mixed>, manifestPath: string, resultsPath: string, outputDir: string}
     */
    public function run(array $manifest, bool $quiet = false, bool $headed = false): array
    {
        $outputDir = $this->outputDir();
        FileHelper::createDirectory($outputDir);
        FileHelper::clearDirectory($outputDir);

        $manifestPath = $outputDir . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        $resultsPath = $outputDir . DIRECTORY_SEPARATOR . self::RESULTS_FILE;
        file_put_contents($manifestPath, Json::encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $command = [
            Plugin::getInstance()->getSettings()->nodeBinary,
            self::runnerScript(),
            '--manifest', $manifestPath,
            '--results', $resultsPath,
        ];
        if ($quiet) {
            $command[] = '--quiet';
        }
        if ($headed) {
            $command[] = '--headed';
        }

        $exitCode = $this->exec($command, (string)Craft::getAlias('@root'));

        $results = is_file($resultsPath) ? Json::decodeIfJson((string)file_get_contents($resultsPath)) : null;
        if (!is_array($results)) {
            $results = [
                'runs' => [],
                'error' => $exitCode === 127
                    ? "Could not start Node (“{$command[0]}”). Run `php craft component-check/doctor`."
                    : "The runner exited with code {$exitCode} without writing results. Run `php craft component-check/doctor`.",
            ];
        }

        return [
            'results' => $results,
            'manifestPath' => $manifestPath,
            'resultsPath' => $resultsPath,
            'outputDir' => $outputDir,
        ];
    }

    public function outputDir(): string
    {
        $base = (string)Craft::getAlias(Plugin::getInstance()->getSettings()->outputPath);
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'latest';
    }

    public static function runnerScript(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'run.mjs';
    }

    public static function doctorScript(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'doctor.mjs';
    }

    /**
     * Runs a command with the runner's progress going to our stderr, so that
     * stdout stays clean for `--json`.
     *
     * @param string[] $command
     */
    public function exec(array $command, string $cwd): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => defined('STDERR') ? STDERR : ['pipe', 'w'],
            2 => defined('STDERR') ? STDERR : ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return 127;
        }
        fclose($pipes[0]);

        return proc_close($process);
    }

    /**
     * Runs a command and captures its stdout.
     *
     * @param string[] $command
     * @return array{exitCode: int, stdout: string}
     */
    public function capture(array $command, string $cwd): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['exitCode' => 127, 'stdout' => ''];
        }
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout];
    }
}
