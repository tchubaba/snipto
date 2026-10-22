<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * Registered as GLOBAL middleware (bootstrap/app.php), not in the web
     * group: group middleware only run when a route matches, so a request
     * that fails the slug route regex (e.g. an accented slug) never reaches
     * the web group and its 404 page would render in the default locale.
     *
     * Because global middleware run before EncryptCookies (web group), the
     * user_locale cookie is still an encrypted payload here; we decrypt it
     * the same way EncryptCookies would.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = array_keys(config('app.supported_locales'));

        // 1. Check cookie for locale.
        $locale = $this->localeFromCookie($request);

        if ( ! $locale) {
            // 2. Detect browser language
            $browserLocale = $request->getPreferredLanguage($supportedLocales);
            $locale        = str_replace('-', '_', $browserLocale ?? config('app.locale'));
        }

        if ( ! in_array($locale, $supportedLocales)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }

    private function localeFromCookie(Request $request): ?string
    {
        $raw = $request->cookies->get('user_locale');

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $decrypted = App::make('encrypter')->decrypt($raw, false);

            return CookieValuePrefix::remove($decrypted, 'user_locale');
        } catch (DecryptException) {
            // Not an encrypter payload (e.g. a hand-set plaintext cookie).
            // Fall through: the caller validates against the supported set.
            return is_string($raw) ? $raw : null;
        }
    }
}
