<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Snipto')</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Snipto" />
    <link rel="manifest" href="/site.webmanifest" />

    <script>
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
    @vite(['resources/css/app.css'])
    @yield('header-js')
</head>
<body class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col">
<!-- Header -->
<header class="fixed top-0 left-0 w-full border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 z-10">
    <div class="max-w-6xl mx-auto px-4 py-3 flex justify-between items-center">
        <a href="/" class="flex items-center">
            <picture>
                <!-- Dark theme image -->
                <source srcset="/images/dark_snipto_logo.png" media="(prefers-color-scheme: dark)">
                <!-- Light theme image (fallback) -->
                <img src="/images/light_snipto_logo.png" alt="Snipto"
                     class="h-9 sm:h-9 md:h-11 w-auto">
            </picture>
        </a>
    </div>
</header>

<!-- Main Content -->
<main class="flex-1 flex items-center justify-center p-6 pt-20 pb-20 overflow-y-auto">
    @yield('content')
</main>

<!-- Footer -->
<footer class="fixed bottom-0 left-0 w-full border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 z-10">
    <div class="max-w-6xl mx-auto px-4 py-4 flex justify-center space-x-6 text-sm text-gray-600 dark:text-gray-400">
        <a href="/faq" class="hover:text-indigo-500 dark:hover:text-indigo-400">FAQ</a>
        <a href="/terms" class="hover:text-indigo-500 dark:hover:text-indigo-400">Terms</a>
        <a href="/contact" class="hover:text-indigo-500 dark:hover:text-indigo-400">Contact</a>
    </div>
</footer>
@yield('footer-js')
</body>
</html>
