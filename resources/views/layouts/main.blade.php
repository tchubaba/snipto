<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Snipto')</title>

    <!-- Immediately set dark mode to prevent flash -->
    <script>
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])
    <script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
</head>
<body class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 min-h-screen flex flex-col">

<!-- Header -->
<header class="w-full border-b border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900">
    <div class="max-w-6xl mx-auto px-4 py-3 flex justify-between items-center">
        <a href="/" class="text-xl font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 dark:hover:text-indigo-300 transition">
            Snipto
        </a>
    </div>
</header>

<!-- Main Content -->
<main class="flex-1 flex items-center justify-center p-6">
    @yield('content')
</main>

<!-- Footer -->
<footer class="w-full border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900">
    <div class="max-w-6xl mx-auto px-4 py-4 flex justify-center space-x-6 text-sm text-gray-600 dark:text-gray-400">
        <a href="/faq" class="hover:text-indigo-500 dark:hover:text-indigo-400">FAQ</a>
        <a href="/terms" class="hover:text-indigo-500 dark:hover:text-indigo-400">Terms</a>
        <a href="/contact" class="hover:text-indigo-500 dark:hover:text-indigo-400">Contact</a>
    </div>
</footer>

</body>
</html>
