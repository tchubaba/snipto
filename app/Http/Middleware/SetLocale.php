<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = array_keys(config('app.supported_locales'));

        // 1. Check cookie for locale
        $locale = $request->cookie('user_locale');

        if ( ! $locale) {
            // 2. Detect browser language
            $browserLocale = $request->getPreferredLanguage($supportedLocales);
            $locale        = str_replace('-', '_', $browserLocale ?? config('app.locale'));
        }

        if ( ! in_array($locale, $supportedLocales)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        // Queue the locale cookie with a 10-year expiration (5256000 minutes)
//        \cookie('user_locale', $locale);

        return $next($request);
    }
}
