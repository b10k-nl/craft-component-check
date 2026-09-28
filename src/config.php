<?php

/**
 * Component Check config.
 *
 * Copy this file to `config/component-check.php` in your project and
 * adjust. Every setting is optional; these are the defaults.
 *
 * Craft's multi-environment config works here too:
 *
 *     return [
 *         '*' => [...],
 *         'staging' => ['mode' => 'full'],
 *     ];
 */

use craft\helpers\App;

return [
    // '' (auto: full where allowAdminChanges is true, off elsewhere),
    // 'off', 'readonly' or 'full'. The COMPONENT_CHECK_MODE env var is
    // read when this is empty.
    'mode' => App::env('COMPONENT_CHECK_MODE') ?? '',

    // Rewrite the scheme/host of discovered URLs, e.g. 'http://web' in CI.
    'baseUrl' => App::env('COMPONENT_CHECK_BASE_URL') ?? '',

    // Matrix field handles to scan. Empty = all.
    'fields' => [],

    // Pages per component variant, and a cap per component.
    'samplesPerVariant' => 1,
    'maxPagesPerComponent' => 10,

    'viewports' => [
        'desktop' => ['width' => 1440, 'height' => 900],
        'mobile' => ['width' => 390, 'height' => 844],
    ],

    // Error messages containing any of these are ignored.
    'ignoreErrors' => [],

    // Requests to URLs containing any of these are blocked in the browser.
    'blockRequests' => [
        'googletagmanager.com',
        'google-analytics.com',
        'connect.facebook.net',
        'hotjar.com',
    ],

    'outputPath' => '@storage/component-check',
    'nodeBinary' => 'node',
    'timeout' => 30000,
    'concurrency' => 4,

    // Pixels an element may move or resize before a snapshot comparison
    // reports it.
    'tolerance' => 2,
    'ignoreHttpsErrors' => true,
];
