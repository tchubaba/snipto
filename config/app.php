<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'supported_locales' => [
        'en'    => ['name' => 'English', 'flag' => 'us'],
        'pt_BR' => ['name' => 'Português', 'flag' => 'br'],
        'es'    => ['name' => 'Español (AI)', 'flag' => 'es'],
        'fr'    => ['name' => 'Français (AI)', 'flag' => 'fr'],
        'de'    => ['name' => 'Deutsch (AI)', 'flag' => 'de'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Addresses of reverse proxies whose X-Forwarded-* headers Laravel may
    | believe, so that $request->ip() reports the real client rather than the
    | proxy. Applied in AppServiceProvider::boot(), which runs after the config
    | files load — setting it from bootstrap/app.php would evaluate env() before
    | .env has been read and silently do nothing.
    |
    | Leave this UNSET unless a proxy actually sits in front of the app:
    |
    |   - No proxy (the Docker Hub image exposed directly on :8080): the client's
    |     TCP connection terminates at the container, so REMOTE_ADDR is already
    |     the real client address. Nothing to configure, and setting this would
    |     let any matching peer assert whatever client IP it likes — which for a
    |     rate limiter means every request picks its own throttle key and no ban
    |     ever sticks.
    |
    |   - The shipped production stack (make prod-up): also leave it unset. Nginx
    |     resolves the real client address with the real_ip module before PHP-FPM
    |     sees the request, so REMOTE_ADDR is already correct and the trust check
    |     below would never match.
    |
    |   - Your own Caddy/Traefik/nginx in front of the :8080 image: set this to
    |     that proxy's address as the app container sees it — a specific IP, a
    |     CIDR, or a comma-separated list. Never '*': that trusts the client
    |     itself. Trusting a whole Docker network CIDR works but means any
    |     container on that network can forge the client address.
    |
    | Only X-Forwarded-For is honoured (see AppServiceProvider); host, proto and
    | port headers stay untrusted.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'  => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
