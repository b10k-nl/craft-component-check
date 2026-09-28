<?php

namespace b10k\componentcheck\services;

/**
 * Short-lived signed tokens that unlock component markers in HTML.
 *
 * Markers are only rendered when a request carries a valid token in the
 * {@see HEADER} header. The token is created by the `test` command, which
 * requires shell access to the server, so nobody without that access can make
 * a page render markers — and a page with markers is marked `no-store` so it
 * never lands in a shared cache.
 *
 * Format: `<expiry unix time>.<hex HMAC-SHA256 of the expiry>`. The key is
 * Craft's security key. Craft-free so it can be unit-tested with a fixed clock.
 */
final class MarkerToken
{
    public const HEADER = 'X-Component-Check';

    public static function create(string $key, int $now, int $ttl = 3600): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A signing key is required.');
        }
        $expires = $now + max(1, $ttl);
        return $expires . '.' . self::sign($key, (string)$expires);
    }

    public static function verify(?string $token, string $key, int $now): bool
    {
        if ($token === null || $token === '' || $key === '') {
            return false;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }

        [$expires, $signature] = $parts;

        if ((int)$expires < $now) {
            return false;
        }

        return hash_equals(self::sign($key, $expires), $signature);
    }

    private static function sign(string $key, string $payload): string
    {
        return hash_hmac('sha256', 'component-check|' . $payload, $key);
    }
}
