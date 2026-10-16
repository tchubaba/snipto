<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Trusted proxies are applied here rather than through
         * ->withMiddleware(fn) { $middleware->trustProxies(...) } in
         * bootstrap/app.php. That closure is registered as an
         * afterResolving(HttpKernel::class) callback, so it runs when the kernel
         * is resolved — before the bootstrappers, and therefore before
         * LoadEnvironmentVariables. An env() call there sees only real process
         * environment variables and silently returns null for anything set in
         * .env. boot() runs after the config files load, so the value is read
         * the same way whether it came from .env, `docker run -e`, or compose,
         * and it survives `php artisan optimize` caching.
         *
         * Unset by default, and it must stay unset unless a proxy genuinely sits
         * in front of the app — see the Trusted Proxies block in config/app.php.
         */
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies);

            // Narrow the trusted header set to the client address alone.
            // Trusting X-Forwarded-Host/-Proto/-Port from a mis-specified proxy
            // poisons generated URLs, isSecure(), and anything caching on Host.
            TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR);
        }
    }
}
