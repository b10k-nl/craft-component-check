<?php

namespace b10k\componentcheck\console\controllers;

use b10k\componentcheck\helpers\Cli;
use craft\helpers\Console;

/**
 * Component Check — what the commands are.
 *
 *     php artisan craft:component-check:default
 *
 * Craft 6: a bare `craft:component-check` is an Artisan namespace, not a
 * command — Artisan lists the namespace and exits 1. The overview is
 * `craft:component-check:default`.
 *
 * Commands:
 *
 *     craft:component-check:doctor               Check the setup
 *     craft:component-check:discover [hero,…]    Where components are used, what would be tested
 *     craft:component-check:snapshot [hero,…]    Record how blocks look now
 *     craft:component-check:test [hero,…]        Check the pages (and compare with the snapshot)
 *     craft:component-check:watch [hero,…]       Snapshot once, re-check on every save
 */
class DefaultController extends BaseController
{
    /**
     * Lists the Component Check commands.
     */
    public function actionIndex(): int
    {
        $plugin = $this->plugin();

        $this->stdout("Component Check\n", Console::BOLD);
        $this->stdout($plugin->explainMode() . "\n\n", Console::FG_GREY);

        $commands = [
            ['doctor', '', 'Check the setup: mode, Node, Playwright, Chromium, the site'],
            ['discover', '[hero,…]', 'Where each component is used, and which pages would be tested'],
            ['snapshot', '[hero,…]', 'Record how the blocks look now, before a change'],
            ['test', '[hero,…]', 'Check the pages, and compare with the snapshot if there is one'],
            ['watch', '[hero,…]', 'Snapshot once, then re-check on every save (own terminal)'],
        ];
        // One column wide enough for the longest command: Craft 6's
        // `ddev artisan craft:…` form is much longer than `php craft …` was.
        $lines = array_map(static fn(array $c): string => Cli::craft("component-check:{$c[0]} {$c[1]}"), $commands);
        $width = max(array_map('mb_strlen', $lines)) + 2;
        foreach ($commands as $i => [$name, $args, $what]) {
            $this->stdout('  ' . $lines[$i] . str_repeat(' ', $width - mb_strlen($lines[$i])), Console::FG_GREEN);
            $this->stdout("{$what}\n");
        }

        $this->stdout("\nOptions per command: " . Cli::craft('component-check:<command> --help') . "\n", Console::FG_GREY);

        return self::EXIT_PASSED;
    }
}
