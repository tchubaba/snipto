<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Progressive Rate Limiter Definitions
    |--------------------------------------------------------------------------
    |
    | Each entry under 'limiters' defines a named progressive rate limiter that
    | can be applied to a route via the 'progressive.throttle:<name>' middleware.
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
    |   2. Manual intervention via: php artisan snipto:unban <ip>
    |
    | ADDING A NEW LIMITER
    | --------------------
    | 1. Add an entry here with the desired tiers, lockout_seconds, offense_ttl.
    | 2. Apply it to a route: ->middleware('progressive.throttle:<limiter-name>')
    |
    */

    'limiters' => [

        /*
         | show-snipto
         | Applied to: GET /api/snipto/{slug}  and  POST /api/snipto/{slug}/viewed
         |
         | Covers snippet retrieval. Three tiers provide a generous baseline for
         | legitimate users while escalating quickly against scrapers or brute-force
         | attempts on password-protected or Snipto ID snippets.
         */
        'show-snipto' => [
            'tiers' => [
                [20, 60],   // Tier 0: 10 attempts per 1 minute  (baseline)
                [10, 120],  // Tier 1: 10 attempts per 2 minutes (first offense)
                [5,  300],  // Tier 2:  5 attempts per 5 minutes (second offense)
            ],
            'lockout_seconds' => 43200, // 12 hours  — third offense triggers full lockout
            'offense_ttl'     => 7200, // 2 hours  — offense counter resets after 2h clean
        ],

        /*
         | store-snipto
         | Applied to: POST /api/snipto
         |
         | Covers snippet creation. Two tiers are sufficient; creation is less
         | latency-sensitive and spam attempts should be cut off faster.
         */
        'store-snipto' => [
            'tiers' => [
                [10, 60],  // Tier 0: 10 attempts per 1 minute  (baseline)
                [5,  300], // Tier 1:  5 attempts per 5 minutes (first offense)
            ],
            'lockout_seconds' => 43200, // 12 hours  — second offense triggers full lockout
            'offense_ttl'     => 7200, // 2 hours  — offense counter resets after 2h clean
        ],

    ],

];
