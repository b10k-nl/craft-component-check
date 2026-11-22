<?php

namespace b10k\componentcheck\helpers;

/**
 * The key marker tokens are signed with.
 *
 * Craft 6 has no `securityKey` in general config any more: the application
 * key is Laravel's `APP_KEY` (`config('app.key')`). Reading `securityKey`
 * there gives an empty string, so no token was issued and every run quietly
 * fell back to page-level checks — no block markers, no snapshots.
 */
final class SigningKey
{
    public static function get(): string
    {
        return (string)config('app.key');
    }
}
