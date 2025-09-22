@extends('layouts.main')

@section('title', 'Snipto - Encrypted Sharing Made Simple')

@section('content')
    <div class="flex flex-col items-center justify-center text-center space-y-8 max-w-2xl w-full">
        <!-- Hero Section -->
        <div class="space-y-4">
            <h1 class="text-4xl sm:text-5xl font-extrabold text-indigo-500">Snipto</h1>
            <p class="text-lg sm:text-xl text-gray-600 dark:text-gray-300">
                An end-to-end encrypted paste service.<br>
                Share sensitive text securely. No accounts, no hassle.
            </p>
        </div>

        <!-- Jump to Snipto Form -->
        <div class="w-full">
            <form action="/" method="GET" onsubmit="return goToSnipto(event)">
                <div class="flex items-center justify-center bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-200 dark:border-gray-700">
                    <span class="px-3 text-gray-500 dark:text-gray-400 select-none">https://snipto.net/</span>
                    <input id="slugInput" type="text" name="slug" placeholder="your-snipto"
                           class="flex-1 p-3 bg-transparent focus:outline-none text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500">
                    <button type="submit"
                            class="bg-indigo-500 text-white px-5 py-3 hover:bg-indigo-600 transition font-bold text-lg">
                        &gt;
                    </button>
                </div>
            </form>
        </div>

        <!-- Quick Pitch -->
        <div class="space-y-2 text-gray-600 dark:text-gray-300">
            <p>🔒 End-to-end encryption ensures only you and your recipient can read the text.</p>
            <p>⏳ Sniptos automatically expire after a set time or number of views.</p>
            <p>⚡ Simple, fast, and private — no signups required.</p>
        </div>
    </div>

    <script>
        function goToSnipto(e) {
            e.preventDefault();
            const slug = document.getElementById('slugInput').value.trim();
            if (slug) {
                window.location.href = '/' + slug;
            }
            return false;
        }
    </script>
@endsection
