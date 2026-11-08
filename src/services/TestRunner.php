<?php

namespace b10k\componentcheck\services;

use b10k\componentcheck\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\App;
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
    public function manifest(array $samples, ?array $only, ?array $viewports, bool $withToken, int $tokenTtl = 3600): array
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
            $token = $key !== '' ? MarkerToken::create($key, time(), $tokenTtl) : null;
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
                'tolerance' => $settings->tolerance,
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

    /**
     * Runs watch mode in the foreground until the developer quits. The Node
     * process gets this terminal (stdin for its keys, stdout for its output).
     *
     * @param array<string, mixed> $manifest
     * @param string[] $paths Absolute directories to watch.
     * @param string[] $ignore Folder names to skip.
     * @param string[]|null $impactCommand Component Map's impact command, to
     *        re-check only the blocks a saved file affects.
     */
    public function watch(array $manifest, array $paths, array $ignore, bool $keepSnapshot, bool $headed = false, ?array $impactCommand = null, ?string $snapshotDir = null): int
    {
        $outputDir = $this->outputDir();
        FileHelper::createDirectory($outputDir);
        FileHelper::clearDirectory($outputDir);

        $manifest['snapshot'] = ['action' => 'watch', 'dir' => $snapshotDir ?? $this->snapshotDir()];
        $manifestPath = $outputDir . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        file_put_contents($manifestPath, Json::encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $settings = Plugin::getInstance()->getSettings();
        $command = [
            $settings->nodeBinary,
            dirname(self::runnerScript()) . DIRECTORY_SEPARATOR . 'watch.mjs',
            '--manifest', $manifestPath,
            '--results', $outputDir . DIRECTORY_SEPARATOR . self::RESULTS_FILE,
            '--interval', (string)$settings->watchInterval,
        ];
        foreach ($paths as $path) {
            array_push($command, '--path', $path);
        }
        foreach ($ignore as $name) {
            array_push($command, '--ignore', $name);
        }
        if ($keepSnapshot) {
            $command[] = '--keep-snapshot';
        }
        if ($impactCommand !== null) {
            array_push($command, '--impact', Json::encode($impactCommand));
        }
        if ($headed) {
            $command[] = '--headed';
        }

        $descriptors = [
            0 => defined('STDIN') ? STDIN : ['pipe', 'r'],
            1 => defined('STDOUT') ? STDOUT : ['pipe', 'w'],
            2 => defined('STDERR') ? STDERR : ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, (string)Craft::getAlias('@root'));
        if (!is_resource($process)) {
            return 127;
        }

        return proc_close($process);
    }

    public function outputDir(): string
    {
        return $this->basePath() . DIRECTORY_SEPARATOR . 'latest';
    }

    /**
     * Where before/after snapshots are kept. Never cleared by a test run;
     * `snapshot` adds to it (a snapshot of `hero` keeps the one of `cards`).
     *
     * @param string|null $path `--snapshot-path`, overriding the setting.
     */
    public function snapshotDir(?string $path = null): string
    {
        $path = trim((string)($path ?? ''));
        if ($path === '') {
            $path = trim(App::parseEnv(Plugin::getInstance()->getSettings()->snapshotPath) ?? '');
        }
        if ($path === '') {
            return $this->defaultSnapshotDir();
        }
        $path = (string)Craft::getAlias($path);
        if (!self::isAbsolute($path)) {
            $path = Craft::getAlias('@root') . DIRECTORY_SEPARATOR . $path;
        }
        return rtrim($path, '/\\');
    }

    /**
     * The local snapshot folder, used when snapshotPath is not set.
     */
    public function defaultSnapshotDir(): string
    {
        return $this->basePath() . DIRECTORY_SEPARATOR . 'snapshot';
    }

    /**
     * When the snapshot was last written, or null if there is none.
     */
    public function snapshotTakenAt(?string $dir = null): ?string
    {
        $index = self::readSnapshotIndex($dir ?? $this->snapshotDir());
        return is_string($index['updatedAt'] ?? null) ? $index['updatedAt'] : null;
    }

    /**
     * Deletes a snapshot: only the files it lists, then its index. Never the
     * folder itself — it may be a folder in the repository with other files.
     *
     * @return int Files deleted.
     */
    public function clearSnapshot(string $dir): int
    {
        $index = self::readSnapshotIndex($dir);
        if ($index === null) {
            return 0;
        }
        $deleted = 0;
        $real = realpath($dir);
        foreach ((array)($index['blocks'] ?? []) as $entry) {
            foreach (['geometry', 'screenshot'] as $key) {
                $file = is_array($entry) && is_string($entry[$key] ?? null) ? realpath($dir . DIRECTORY_SEPARATOR . $entry[$key]) : false;
                // Only inside the snapshot folder, whatever the index says.
                if ($file !== false && $real !== false && str_starts_with($file, $real . DIRECTORY_SEPARATOR) && @unlink($file)) {
                    $deleted++;
                }
            }
        }
        @unlink($dir . DIRECTORY_SEPARATOR . 'index.json');
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub); // only if now empty
        }
        return $deleted;
    }

    /**
     * A snapshot's index.json, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public static function readSnapshotIndex(string $dir): ?array
    {
        $file = $dir . DIRECTORY_SEPARATOR . 'index.json';
        if (!is_file($file)) {
            return null;
        }
        $data = Json::decodeIfJson((string)file_get_contents($file));
        return is_array($data) ? $data : null;
    }

    private static function isAbsolute(string $path): bool
    {
        // /unix, \\server\share, C:\windows or C:/windows
        return str_starts_with($path, '/') || str_starts_with($path, '\\')
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' && in_array($path[2], ['/', '\\'], true));
    }

    private function basePath(): string
    {
        $base = (string)Craft::getAlias(Plugin::getInstance()->getSettings()->outputPath);
        return rtrim($base, '/\\');
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
