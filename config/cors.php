<?php

declare(strict_types=1);

/*
 * Snipto is a strictly same-origin application: every fetch from the frontend
 * uses `credentials: 'same-origin'` and relative URLs, and the API routes are
 * defined in routes/web.php (not api/*), so no endpoint is ever called
 * cross-origin by design.
 *
 * Without this file Laravel's framework defaults apply — paths
 * ['api/*', 'sanctum/csrf-cookie'] with allowed_origins ['*'] — which made
 * HandleCors answer OPTIONS preflights and echo Access-Control-Allow-Origin: *
 * on any /api/* request (audit finding F-1, live on production). Nothing here
 * needs that: the zero-knowledge model assumes the browser, not other sites,
 * talks to this API, and wildcard CORS would let a malicious page probe slugs
 * and consume reveal tokens from the victim's browser context.
 *
 * Empty `paths` disables the CORS middleware for every route.
 */

return [
    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
