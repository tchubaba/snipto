<?php

use App\Http\Middleware\CrossOriginIsolation;
use App\Http\Middleware\CspReportingEndpoints;
use App\Http\Middleware\SetLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Spatie\Csp\AddCspHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetLocale is global, not web-group: group middleware only run when a
        // route matches, so route-less responses (404s from slugs that fail the
        // route regex) would otherwise render in the default locale.
        $middleware->prepend(SetLocale::class);
        $middleware->web([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);
        $middleware->append([
            AddCspHeaders::class,
            CrossOriginIsolation::class,
            // Maps the CSP report-to group to the collector URL; runs after
            // AddCspHeaders so the CSP header it complements is in place.
            CspReportingEndpoints::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Storage hygiene (audit finding F-3): expired rows — including
        // plaintext payloads — are deleted instead of lingering until their
        // next read. The shipped Docker images start `schedule:work` in
        // their entrypoints, so this fires automatically in dev, prod
        // compose, and the Docker Hub image. Bare-metal / custom stacks
        // must tick the schedule themselves (`schedule:run` from a crontab
        // or `schedule:work` under a supervisor), or run
        // `artisan sniptos:purge` directly on their own cadence.
        $schedule->command('sniptos:purge')
            ->everyFifteenMinutes()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
