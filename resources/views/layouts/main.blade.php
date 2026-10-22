<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="csp-nonce" content="{{ app('csp-nonce') }}">
    <title>@yield('title', 'Snipto')</title>
    <meta name="description" content="An end-to-end encrypted pastebin for ephemeral text. Snippets self-destruct after being read.">
    <link rel="canonical" href="https://snipto.net/">
    <meta name="robots" content="index, follow">

    <meta property="og:title" content="Snipto.net – Secure, Ephemeral, Encrypted Messages">
    <meta property="og:description" content="An end-to-end encrypted pastebin for ephemeral text. Snippets self-destruct after being read.">
    <meta property="og:url" content="https://snipto.net/">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Snipto" />
    <link rel="manifest" href="/site.webmanifest" />

    {{--
        Theme. One class on <html> is the single source of truth: Tailwind's
        dark: utilities (see the @custom-variant in resources/css/app.css), the
        logo swap, and the sandboxed snippet iframe's text colour all read it.
        A saved choice wins; with none we follow the device.

        This must stay inline and ahead of the @vite stylesheet below — a script
        that ran later would paint the wrong theme first and then flip it.
    --}}
    <script @cspNonce>
        window.SNIPTO_THEME = (function () {
            var KEY = 'snipto-theme';

            function saved() {
                try {
                    var v = window.localStorage.getItem(KEY);
                    return (v === 'dark' || v === 'light') ? v : null;
                } catch (e) {
                    // Private browsing modes can throw on access alone; fall back
                    // to the device rather than failing the whole page.
                    return null;
                }
            }

            function fromDevice() {
                return window.matchMedia
                    && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            function current() {
                return saved() || fromDevice();
            }

            function apply(theme) {
                document.documentElement.classList.toggle('dark', theme === 'dark');
                // Lets the header control keep its label in step even when the
                // theme changes without a click, i.e. from the device listener.
                window.dispatchEvent(new CustomEvent('snipto:theme-change', { detail: theme }));
                return theme;
            }

            return {
                current: current,
                apply: apply,
                // Flips the theme and remembers it. Applies even when storage is
                // unavailable, so the control still works for this page.
                toggle: function () {
                    var next = current() === 'dark' ? 'light' : 'dark';
                    try {
                        window.localStorage.setItem(KEY, next);
                    } catch (e) {
                        // Not fatal: the change stands until the next load.
                    }
                    return apply(next);
                },
                // True while the theme is still coming from the device.
                followsDevice: function () {
                    return saved() === null;
                },
                init: function () {
                    apply(current());
                    // Someone who has never touched the control keeps tracking
                    // their device, including when the OS flips at sunset.
                    if (window.matchMedia) {
                        var mq = window.matchMedia('(prefers-color-scheme: dark)');
                        var onChange = function () {
                            if (saved() === null) apply(fromDevice());
                        };
                        if (mq.addEventListener) {
                            mq.addEventListener('change', onChange);
                        } else if (mq.addListener) {
                            mq.addListener(onChange);
                        }
                    }
                }
            };
        })();

        window.SNIPTO_THEME.init();
    </script>
    @yield('alpine-translations')

    @yield('header-js')
    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
    ])
</head>
<body class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col">
<!-- Header -->
<header class="fixed top-0 left-0 w-full border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 z-10">
    <div class="max-w-6xl mx-auto px-4 py-3 flex justify-between items-center">
        <!-- Logo -->
        <a href="/" class="flex items-center">
            {{--
                A <picture><source media="(prefers-color-scheme: dark)"> cannot
                follow a manual override, so both files ship as <img> and CSS
                shows the one matching the .dark class (see .logo-* in
                resources/css/app.css). Kept as <img> rather than a CSS
                background so the hero instance on the landing page stays a
                largest-contentful-paint candidate.
            --}}
            {{-- shrink-0: the header row is tight on a phone, and without it
                 flexbox squeezes the logo instead of reporting the overflow,
                 which squashes its aspect ratio. --}}
            <img src="/images/light_snipto_logo.png" alt="Snipto" class="logo-light shrink-0 h-9 sm:h-9 md:h-11 w-auto">
            <img src="/images/dark_snipto_logo.png" alt="Snipto" class="logo-dark shrink-0 h-9 sm:h-9 md:h-11 w-auto">
        </a>

        <div class="flex items-center space-x-4">
            <!-- Get Snipto ID Link -->
            <a href="{{ route('sniptoid') }}" class="flex items-center space-x-2 px-3 py-2 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 dark:hover:text-indigo-300 transition-colors duration-200">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
                <span class="hidden sm:inline">{{ __('Get your Snipto ID') }}</span>
            </a>

            <!-- Language Dropdown -->
            <div class="relative inline-block" x-data="localeDropdown()">
                <button @click="open = !open"
                        class="flex items-center space-x-2 px-3 py-2 border rounded-md border-gray-300 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring">
                    <span :class="'fi fi-' + current.flag" class="mr-2 w-5 h-4 rounded-sm overflow-hidden"></span>
                    {{-- Name only from sm up: on a phone the row has to fit the
                         logo, this control and the theme toggle. The full names
                         are still listed inside the open dropdown. --}}
                    <span x-text="current.name" class="hidden sm:inline"></span>
                    <svg class="w-3 h-3 ml-1" fill="none" stroke="currentColor" stroke-width="2"
                         viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="open" x-transition @click.away="open = false"
                     class="absolute right-0 mt-2 w-40 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg z-50">
                    <template x-for="(locale, code) in locales" :key="code">
                        <form method="POST" action="{{ route('locale.change') }}" @submit.prevent="submitLocaleForm($event)">
                            @csrf
                            <input type="hidden" name="locale" :value="code">
                            <button type="submit"
                                    class="flex items-center w-full px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 text-left">
                                <span :class="'fi fi-' + locale.flag" class="mr-2 w-5 h-4 rounded-sm overflow-hidden"></span>
                                <span x-text="locale.name"></span>
                            </button>
                        </form>
                    </template>
                </div>
            </div>

            {{--
                Theme toggle. The icons are switched by CSS off the .dark class
                (see .theme-icon-* in resources/css/app.css) rather than by
                Alpine, so the correct icon is already showing before Alpine
                boots. Alpine only carries the click and the accessible name.
                Toggling touches no navigation, so an open snippet survives.
            --}}
            <button type="button"
                    x-data="themeToggle()"
                    @click="toggle()"
                    @snipto:theme-change.window="isDark = $event.detail === 'dark'"
                    :title="label()"
                    :aria-label="label()"
                    :aria-pressed="isDark"
                    class="flex items-center justify-center px-2 py-2 border rounded-md border-gray-300 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring">
                <!-- Sun: shown in dark mode, i.e. click to go light -->
                <svg class="theme-icon-sun w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <!-- Moon: shown in light mode, i.e. click to go dark -->
                <svg class="theme-icon-moon w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
            </button>
        </div>

        <!-- Optional: other header items can go here -->
    </div>

    <!-- Alpine script for dropdown and theme toggle -->
    <script @cspNonce>
        document.addEventListener('alpine:init', () => {
            Alpine.data('themeToggle', () => ({
                isDark: document.documentElement.classList.contains('dark'),
                toggle() {
                    // Deliberately no navigation: on the snippet view a reload
                    // would consume the one-time payload.
                    this.isDark = window.SNIPTO_THEME.toggle() === 'dark';
                },
                label() {
                    return this.isDark
                        ? @json(__('Switch to light mode'))
                        : @json(__('Switch to dark mode'));
                }
            }));

            Alpine.data('localeDropdown', () => ({
                open: false,
                locales: @json(config('app.supported_locales')),
                // The effective request locale (cookie/browser detection via
                // the global SetLocale middleware), not a session value that
                // nothing writes — this also keeps the toggle correct on the
                // 404 page, where no session exists.
                locale: @json(app()->getLocale()),
                get current() {
                    return this.locales[this.locale] || Object.values(this.locales)[0];
                },
                submitLocaleForm(event) {
                    let eventObj = new CustomEvent('request-language-change', { detail: { form: event.target }, cancelable: true });
                    window.dispatchEvent(eventObj);
                    if (!eventObj.defaultPrevented) {
                        event.target.submit();
                    }
                }
            }));
        });
    </script>
</header>

<!-- Main Content -->
<!--
    Horizontal padding matches the header/footer inner wrappers (px-4) so page
    content lines up with the logo and language selector.

    items-start + `my-auto` on the page root (rather than items-center) centers
    content when it fits but keeps the top reachable once it overflows: a scroll
    container with align-items:center clips the top of taller-than-viewport
    content and that overflow cannot be scrolled back to.
-->
<main class="flex-1 flex items-start justify-center px-4 pt-20 pb-20 overflow-y-auto">
    @yield('content')
</main>

<!-- Footer -->
<footer class="fixed bottom-0 left-0 w-full border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 z-10">
    <div class="max-w-6xl mx-auto px-4 py-4 flex justify-center space-x-6 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ route('sniptoid') }}" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('Snipto ID') }}</a>
        <a href="/faq" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('FAQ') }}</a>
        <a href="/safety" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('Safety') }}</a>
        <a href="/terms" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('Terms') }}</a>
        <a href="/contact" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('Contact') }}</a>
    </div>
</footer>
@yield('footer-js')
</body>
</html>
