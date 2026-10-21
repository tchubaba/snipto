<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tiered Rate Limiter Definitions
    |--------------------------------------------------------------------------
    |
    | Each entry under 'limiters' defines a named tiered rate limiter that
    | can be applied to a route via the 'tiered.throttle:<name>' middleware.
    |
    | HOW IT WORKS
    | ------------
    | On each request the middleware resolves the IP's current tier from its
    | offense count, then increments a hit counter for that tier's time window.
    | If the counter exceeds the tier's limit, the request is blocked (HTTP 429)
    | and the offense count is incremented by one — but only once per window,
    | no matter how many requests are made while blocked.
    |
    | On the next window the IP starts fresh within its new (stricter) tier.
    | Once all tiers are exhausted the IP is locked out entirely.
    |
    | TIERS
    | -----
    | 'tiers' is an ordered array of [maxAttempts, windowSeconds] pairs.
    | Tiers are traversed from index 0 (most lenient) upward. Each time an IP
    | exceeds a tier's limit it accumulates one offense and moves to the next
    | tier on the following window. Any number of tiers can be defined.
    |
    | Example progression for a 3-tier limiter:
    |   Offense 0 → Tier 0 (most lenient)
    |   Offense 1 → Tier 1
    |   Offense 2 → Tier 2 (strictest)
    |   Offense 3 → Lockout (offense count ≥ number of tiers)
    |
    | LOCKOUT
    | -------
    | 'lockout_seconds' controls how long a locked-out IP is blocked entirely.
    | During lockout all requests return HTTP 429 with the X-RateLimit-Lockout: 1
    | header. When the lockout expires the IP re-enters the last (strictest) tier
    | — it does NOT reset to tier 0 unless the offense TTL has also expired.
    |
    | OFFENSE TTL & FORGIVENESS
    | -------------------------
    | 'offense_ttl' is the number of seconds the offense counter survives without
    | being refreshed. Every new offense resets this TTL. An IP that stops
    | misbehaving long enough for the TTL to expire will return to tier 0 on its
    | next request. Lockout expiry alone does not reset the offense counter.
    |
    | The only ways to reach a full tier-0 reset are:
    |   1. The offense TTL expires naturally (no new offenses during that period).
    |   2. Manual intervention via: php artisan throttle:unban <ip>
    |
    | To inspect what a given IP is currently subject to before clearing it:
    |   php artisan throttle:status <ip>
    |
    | LIMITER NAMES
    | -------------
    | Cache keys are tt_{type}:{limiter}:{ip}, built by plain concatenation, so a
    | limiter name must not contain a colon. A route that names a limiter which is
    | not configured is an error outside production (and a logged error plus an
    | unthrottled pass-through in production), so a typo can never quietly remove
    | rate limiting from a deployed route.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Development Escape Hatches
    |--------------------------------------------------------------------------
    |
    | These exist so local and Docker development does not trip the limiter while
    | hammering the API. None of them can switch throttling off on a live host,
    | because every one of them is gated on the runtime environment being listed
    | in 'bypass_environments'.
    |
    | That gate is an allowlist on purpose. Application::isProduction() matches
    | the string 'production' exactly, so a "not production" denylist would treat
    | 'prod', 'staging' or a typo as non-production and silently drop rate
    | limiting. With an allowlist, an unrecognised environment name disables the
    | bypass, not the limiter. The gate is evaluated per request, so a cached
    | config built on a development machine cannot carry these values onto a host
    | whose environment is not listed here.
    |
    | Production runs with APP_ENV=production (docker-compose.prod.yml), which is
    | deliberately absent from the list below: all three levers are inert there.
    | It is also moot in production — Nginx resolves the real client address with
    | the real_ip module before handing the request to PHP-FPM, so the IP being
    | keyed on is public and would not match 'bypass_private_ranges' anyway.
    |
    | 'enabled'                false switches the middleware off entirely — for
    |                          load tests or seeding. Prefer the bypass levers for
    |                          ordinary development: the middleware keeps running
    |                          for every other IP, so a bug in it cannot hide
    |                          until production.
    | 'bypass_ips'             IPs that skip throttling. Development only; the
    |                          environment gate means committing a list here cannot
    |                          exempt an address on a live host.
    | 'bypass_private_ranges'  Exempts loopback, RFC1918, link-local and other
    |                          reserved ranges, including the Docker bridge
    |                          network — the address the app container sees for
    |                          requests from your own machine. Left on so local
    |                          testing is not throttled; override with
    |                          TIERED_THROTTLE_BYPASS_PRIVATE_RANGES=false when you
    |                          need to exercise the limiter locally.
    |
    | Note that phpunit.xml sets APP_ENV=testing, which IS listed below, so the
    | feature tests that assert rate limiting have to pin this allowlist down to
    | 'production' themselves. See tests/Feature/ApiRateLimitingTest.php.
    |
    */

    'enabled' => env('TIERED_THROTTLE_ENABLED', true),

    'bypass_ips' => [],

    'bypass_private_ranges' => env('TIERED_THROTTLE_BYPASS_PRIVATE_RANGES', true),

    'bypass_environments' => ['local', 'testing'],

    'limiters' => [

        /*
         | show-snipto
         | Applied to: GET /api/snipto/{slug}
         */
        'show-snipto' => [
            'tiers' => [
                [20, 60],   // Tier 0: 20 attempts per 1 minute  (baseline)
                [10, 120],  // Tier 1: 10 attempts per 2 minutes (first offense)
                [5,  300],  // Tier 2:  5 attempts per 5 minutes (second offense)
            ],
            'lockout_seconds' => 43200, // 12 hours
            'offense_ttl'     => 7200, // 2 hours
        ],

        /*
         | store-snipto
         | Applied to: POST /api/snipto
         */
        'store-snipto' => [
            'tiers' => [
                [10, 60],  // Tier 0: 10 attempts per 1 minute  (baseline)
                [5,  300], // Tier 1:  5 attempts per 5 minutes (first offense)
            ],
            'lockout_seconds' => 43200, // 12 hours
            'offense_ttl'     => 7200, // 2 hours
        ],

        /*
         | reveal-snipto
         | Applied to: POST /api/snipto/{slug}/reveal
         | Mirrors show-snipto: every reveal attempt consumes its one-time token, so a
         | brute-force pace requires a fresh metadata GET per attempt anyway — the same
         | escalation profile as the probe endpoint is the right brake here.
         */
        'reveal-snipto' => [
            'tiers' => [
                [20, 60],   // Tier 0: 20 attempts per 1 minute  (baseline)
                [10, 120],  // Tier 1: 10 attempts per 2 minutes (first offense)
                [5,  300],  // Tier 2:  5 attempts per 5 minutes (second offense)
            ],
            'lockout_seconds' => 43200, // 12 hours
            'offense_ttl'     => 7200, // 2 hours
        ],

    ],

];
