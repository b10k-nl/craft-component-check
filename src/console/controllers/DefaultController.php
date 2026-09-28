<?php

namespace b10k\componentcheck\console\controllers;

use craft\helpers\Console;

/**
 * Component Check — what the commands are.
 *
 *     php craft component-check
 *
 * Commands:
 *
 *     component-check/doctor               Check the setup
 *     component-check/discover [hero,…]    Where components are used, what would be tested
 *     component-check/snapshot [hero,…]    Record how blocks look now
 *     component-check/test [hero,…]        Check the pages (and compare with the snapshot)
 *     component-check/watch [hero,…]       Snapshot once, re-check on every save
 */
class DefaultController extends BaseController
{
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
        foreach ($commands as [$name, $args, $what]) {
            $this->stdout(sprintf("  %-38s", "php craft component-check/{$name} {$args}"), Console::FG_GREEN);
            $this->stdout("{$what}\n");
        }

        $this->stdout("\nOptions per command: php craft help component-check/<command>\n", Console::FG_GREY);

        return self::EXIT_PASSED;
    }
}
