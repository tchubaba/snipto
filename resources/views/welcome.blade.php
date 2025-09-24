@extends('layouts.main')

@section('title', 'Snipto - Encrypted Sharing Made Simple')

@section('content')
    <div class="flex flex-col items-center justify-center text-center space-y-8 max-w-2xl w-full relative">
        <!-- Hero Section -->
        <div class="space-y-4">
            <div class="flex justify-center">
                <picture>
                    <!-- Dark theme image -->
                    <source srcset="/images/dark_snipto_logo.png" media="(prefers-color-scheme: dark)">
                    <!-- Light theme image (fallback) -->
                    <img src="/images/light_snipto_logo.png" alt="Snipto"
                         class="w-11/12 max-w-[1440px] h-auto">
                </picture>
            </div>
            <p class="text-lg sm:text-xl text-gray-600 dark:text-gray-300">
                An end-to-end encrypted paste service.<br>
                Share text securely. No accounts, no hassle.
            </p>
        </div>

        <!-- Jump to Snipto Form -->
        <div class="w-full max-w-md mx-auto relative">
            <form action="/" method="GET" onsubmit="return goToSnipto(event)">
                <div class="flex items-center justify-between bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-200 dark:border-gray-700">
                    <span class="px-3 text-gray-500 dark:text-gray-400 select-none">https://snipto.net/</span>
                    <input id="slugInput" type="text" name="slug" placeholder="your-snipto"
                           class="flex-1 p-3 bg-transparent focus:outline-none text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 w-full sm:w-auto">
                    <button type="submit"
                            class="bg-indigo-500 text-white px-5 py-3 hover:bg-indigo-600 transition font-bold text-lg flex-shrink-0">
                        &gt;
                    </button>
                </div>
            </form>

            <!-- Toast Notification -->
            <div id="toast" class="hidden absolute left-0 right-0 mx-auto top-[calc(100%+0.5rem)] max-w-[calc(100%-6rem)] bg-red-500 text-white px-4 py-3 rounded-lg shadow-lg transition-opacity duration-300 opacity-0 z-10">
                <p class="text-sm font-medium">Please use only alphanumeric characters (letters and numbers).</p>
            </div>

            <!-- Terms of Service notice -->
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400 text-center">
                By using Snipto, you agree with the <a href="/terms" class="underline hover:text-indigo-500 dark:hover:text-indigo-400">Terms of Service</a>.
            </p>
        </div>

        <!-- Quick Pitch -->
        <div class="space-y-2 text-gray-600 dark:text-gray-300">
            <p>🔒 End-to-end encryption ensures only you and your recipient can read the text.</p>
            <p>⏳ Sniptos are ephemeral - they are deleted from our systems when viewed.</p>
            <p>⚡ Simple, fast, and private — no signups required.</p>
        </div>
    </div>

    <script>
        function goToSnipto(e) {
            e.preventDefault();
            const slugInput = document.getElementById('slugInput');
            const slug = slugInput.value.trim();
            const toast = document.getElementById('toast');
            const alphanumericRegex = /^[a-zA-Z0-9]+$/;

            if (slug && alphanumericRegex.test(slug)) {
                window.location.href = '/' + slug;
            } else {
                // Show toast notification
                toast.classList.remove('hidden', 'opacity-0');
                toast.classList.add('opacity-100');

                // Auto-hide toast after 3 seconds
                setTimeout(() => {
                    toast.classList.remove('opacity-100');
                    toast.classList.add('opacity-0');
                    setTimeout(() => toast.classList.add('hidden'), 300);
                }, 3000);
            }
            return false;
        }
    </script>
@endsection
