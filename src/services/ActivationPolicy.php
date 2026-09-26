<?php

namespace b10k\componentregression\services;

/**
 * Decides what the plugin is allowed to do in the current environment.
 *
 * The plugin installs everywhere, production included, so that project config
 * stays identical across environments and the Twig helpers in templates never
 * break. What it *does* depends on the mode:
 *
 * - `off`      — nothing. Commands refuse with a non-zero exit code; Twig
 *                helpers render an empty string.
 * - `readonly` — discovery only: `discover` and the manifest. No change to any
 *                HTTP response, so nothing is ever exposed publicly. Tests run
 *                page-level checks only (no component markers).
 * - `full`     — everything, including component markers in rendered HTML,
 *                which only appear on requests carrying a valid signed token.
 *
 * With no explicit setting the mode follows `allowAdminChanges`: on where the
 * schema can be edited (development), off where it cannot (production, and
 * usually staging). A plugin cannot reliably tell staging from production, so
 * an explicit setting always wins — and the parts exposed over HTTP are made
 * safe by design (signed, short-lived tokens) rather than by guessing the
 * environment.
 *
 * Craft-free on purpose: the rule lives here and is unit-tested.
 */
final class ActivationPolicy
{
    public const OFF = 'off';
    public const READONLY = 'readonly';
    public const FULL = 'full';

    public const MODES = [self::OFF, self::READONLY, self::FULL];

    private const RANK = [self::OFF => 0, self::READONLY => 1, self::FULL => 2];

    public static function resolve(string $configured, bool $allowAdminChanges): string
    {
        $configured = strtolower(trim($configured));

        if (in_array($configured, self::MODES, true)) {
            return $configured;
        }

        return $allowAdminChanges ? self::FULL : self::OFF;
    }

    /**
     * Whether `$mode` permits something that needs at least `$required`.
     */
    public static function allows(string $mode, string $required): bool
    {
        return (self::RANK[$mode] ?? 0) >= (self::RANK[$required] ?? PHP_INT_MAX);
    }

    /**
     * One line a human can act on, used by `doctor` and by refusals.
     */
    public static function explain(string $configured, bool $allowAdminChanges): string
    {
        $mode = self::resolve($configured, $allowAdminChanges);
        $configured = strtolower(trim($configured));

        if (in_array($configured, self::MODES, true)) {
            return "Mode “{$mode}”, set explicitly in config/component-regression.php.";
        }

        return $allowAdminChanges
            ? "Mode “{$mode}”: admin changes are allowed, so this looks like development."
            : "Mode “{$mode}”: admin changes are not allowed, so this looks like production. "
                . "Set 'mode' in config/component-regression.php (e.g. from COMPONENT_REGRESSION_MODE) to enable it on staging or in CI.";
    }
}
