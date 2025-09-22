<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Snipto')</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
</head>
<body class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 transition-colors duration-300
             min-h-screen flex flex-col">

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
