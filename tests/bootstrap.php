<?php

/**
 * PHPUnit bootstrap.
 *
 * The core services (activation, tokens, sampling, manifest, report) are
 * Craft-free, so tests only need the Composer autoloader.
 *
 * Uses the plugin's own vendor/ when present (standalone/CI), and otherwise
 * falls back to the host Craft project's autoloader.
 */

$pluginAutoload = __DIR__ . '/../vendor/autoload.php';
$rootAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

if (is_file($pluginAutoload)) {
    $autoload = require $pluginAutoload;
} elseif (is_file($rootAutoload)) {
    $autoload = require $rootAutoload;
} else {
    fwrite(STDERR, "No Composer autoloader found. Run `composer install`.\n");
    exit(1);
}

// The autoloader may have been built for another root package; make sure it
// knows this plugin's namespace either way.
$autoload->addPsr4('b10k\\componentregression\\', __DIR__ . '/../src/');

spl_autoload_register(static function (string $class): void {
    $prefix = 'b10k\\componentregression\\tests\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
