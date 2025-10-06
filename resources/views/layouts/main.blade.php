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

    <script @cspNonce>
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
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
            <picture>
                <source srcset="/images/dark_snipto_logo.png" media="(prefers-color-scheme: dark)">
                <img src="/images/light_snipto_logo.png" alt="Snipto" class="h-9 sm:h-9 md:h-11 w-auto">
            </picture>
        </a>

        <!-- Language Dropdown -->
        <div class="relative inline-block" x-data="localeDropdown()">
            <button @click="open = !open"
                    class="flex items-center space-x-2 px-3 py-2 border rounded-md border-gray-300 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 focus:outline-none focus:ring">
                <span :class="'fi fi-' + current.flag" class="mr-2 w-5 h-4 rounded-sm overflow-hidden"></span>
                <span x-text="current.name"></span>
                <svg class="w-3 h-3 ml-1" fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>

            <div x-show="open" x-transition @click.away="open = false"
                 class="absolute right-0 mt-2 w-40 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg z-50">
                <template x-for="(locale, code) in locales" :key="code">
                    <form method="POST" action="{{ route('locale.change') }}">
                        @csrf
                        <button type="submit" name="locale" :value="code"
                                class="flex items-center w-full px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 text-left">
                            <span :class="'fi fi-' + locale.flag" class="mr-2 w-5 h-4 rounded-sm overflow-hidden"></span>
                            <span x-text="locale.name"></span>
                        </button>
                    </form>
                </template>
            </div>
        </div>

        <!-- Optional: other header items can go here -->
    </div>

    <!-- Alpine script for dropdown -->
    <script @cspNonce>
        function localeDropdown() {
            return {
                open: false,
                locales: @json(config('app.supported_locales')),
                locale: "{{ session('locale', config('app.locale')) }}",
                get current() {
                    return this.locales[this.locale] || Object.values(this.locales)[0];
                }
            }
        }
    </script>
</header>

<!-- Main Content -->
<main class="flex-1 flex items-center justify-center p-6 pt-20 pb-20 overflow-y-auto">
    @yield('content')
</main>

<!-- Footer -->
<footer class="fixed bottom-0 left-0 w-full border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 z-10">
    <div class="max-w-6xl mx-auto px-4 py-4 flex justify-center space-x-6 text-sm text-gray-600 dark:text-gray-400">
        <a href="/faq" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('FAQ') }}</a>
        <a href="/safety" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('Safety') }}</a>
        <a href="/terms" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('Terms') }}</a>
        <a href="/contact" class="hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('Contact') }}</a>
    </div>
</footer>
@yield('footer-js')
</body>
</html>
