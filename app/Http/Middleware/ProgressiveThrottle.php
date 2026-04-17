<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Progressive rate-limiting middleware.
 *
 * Applies a named, tier-based rate limiter to a route. Each IP is assigned a tier
 * determined by its accumulated offense count. Tiers are defined in
 * config/rate_limiting.php under 'limiters.<name>.tiers' as ordered [maxAttempts, windowSeconds]
 * pairs. Tier 0 is the most lenient; higher tiers are progressively stricter.
 *
 * Progression:
 *   - On each request the IP's hit counter for the current window is incremented.
 *   - If the counter exceeds the tier limit, a 429 is returned and the offense count
 *     is incremented by one — but only once per window, regardless of further requests.
 *   - On the next window the IP is re-evaluated against its new (higher) tier.
 *   - Once all tiers are exhausted the IP is locked out for 'lockout_seconds'.
 *   - After lockout expires the IP re-enters the last (strictest) tier unless the
 *     offense TTL has also expired, in which case it resets to tier 0.
 *
 * Cache key scheme (prefix: pt_):
 *   pt_rl:<name>:<ip>       — hit counter for the current window (TTL: window seconds)
 *   pt_window:<name>:<ip>   — window-end Unix timestamp (TTL: window + 5 seconds)
 *   pt_breached:<name>:<ip> — "already escalated this window" flag (TTL: window seconds)
 *   pt_offense:<name>:<ip>  — offense count, selects the active tier (TTL: offense_ttl seconds)
 *   pt_lockout:<name>:<ip>  — lockout flag (TTL: lockout_seconds)
 *
 * Throttle response (429):    X-RateLimit-Reset: <unix timestamp>, Retry-After: <seconds>
 * Lockout response (429):     X-RateLimit-Lockout: 1
 *
 * To unban an IP manually:    php artisan snipto:unban <ip>
 */
class ProgressiveThrottle
{
    /**
     * Handle an incoming request, applying the named progressive rate limiter.
     *
     * Resolves the IP's current tier from its offense count, increments the hit counter
     * for the active window, and either passes the request through or returns a 429.
     *
     * @param  string  $limiterName  Named limiter key in config/rate_limiting.php
     */
    public function handle(Request $request, Closure $next, string $limiterName): Response
    {
        $config = config("rate_limiting.limiters.{$limiterName}");
        $ip     = $request->ip();

        // 1. Check active lockout
        if (Cache::has($this->key('lockout', $limiterName, $ip))) {
            return $this->lockoutResponse();
        }

        // 2. Resolve current tier from offense count
        $offense          = (int) Cache::get($this->key('offense', $limiterName, $ip), 0);
        $tierIndex        = min($offense, count($config['tiers']) - 1);
        [$limit, $window] = $config['tiers'][$tierIndex];

        $rlKey     = $this->key('rl', $limiterName, $ip);
        $windowKey = $this->key('window', $limiterName, $ip);

        // 3. Seed window on first hit (atomic: only sets if key absent)
        if (Cache::add($rlKey, 0, $window)) {
            Cache::put($windowKey, now()->addSeconds($window)->timestamp, $window + 5);
        }

        // 4. Increment hit counter
        $hits = Cache::increment($rlKey);

        // 5. Check limit
        if ($hits > $limit) {
            $breachKey = $this->key('breached', $limiterName, $ip);

            // Escalate offense only once per window
            if (Cache::add($breachKey, 1, $window)) {
                $newOffense = $offense + 1;
                Cache::put($this->key('offense', $limiterName, $ip), $newOffense, $config['offense_ttl']);

                if ($newOffense >= count($config['tiers'])) {
                    Cache::put($this->key('lockout', $limiterName, $ip), 1, $config['lockout_seconds']);

                    return $this->lockoutResponse();
                }
            }

            $resetAt = (int) Cache::get($windowKey, now()->addSeconds($window)->timestamp);

            return $this->throttleResponse($resetAt);
        }

        return $next($request);
    }

    /**
     * Build a namespaced cache key.
     *
     * @param  string  $type  One of: rl, window, breached, offense, lockout
     * @param  string  $limiterName  Named limiter (e.g. "show-snipto")
     * @param  string  $ip  Client IP address
     */
    private function key(string $type, string $limiterName, string $ip): string
    {
        return "pt_{$type}:{$limiterName}:{$ip}";
    }

    /**
     * Return a 429 lockout response.
     *
     * Sets X-RateLimit-Lockout: 1. No reset timestamp is included — the frontend
     * treats its absence as the signal that this is a full lockout, not a windowed
     * throttle.
     */
    private function lockoutResponse(): JsonResponse
    {
        return response()->json(['message' => 'Too Many Attempts.'], 429, [
            'X-RateLimit-Lockout' => '1',
        ]);
    }

    /**
     * Return a 429 throttle response with window expiry headers.
     *
     * @param  int  $resetTimestamp  Unix timestamp when the current window expires
     */
    private function throttleResponse(int $resetTimestamp): JsonResponse
    {
        return response()->json(['message' => 'Too Many Attempts.'], 429, [
            'X-RateLimit-Reset' => $resetTimestamp,
            'Retry-After'       => max(0, $resetTimestamp - time()),
        ]);
    }
}
