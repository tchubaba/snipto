@extends('layouts.main')

@section('header-js')
    <script type="module" src="{{ asset('build/js/sniptoid.js') }}?v={{ file_exists(public_path('build/js/sniptoid.js')) ? filemtime(public_path('build/js/sniptoid.js')) : '0' }}"></script>
@endsection

@section('alpine-translations')
    <script @cspNonce>
        window.i18n =
        @php
            echo json_encode([
                'Your Snipto ID' => __('Your Snipto ID'),
                'Copied to clipboard!' => __('Copied to clipboard!'),
                'Copying failed. Please copy manually.' => __('Copying failed. Please copy manually.'),
                'Generate Snipto ID' => __('Generate Snipto ID'),
                'Enter a passphrase to generate your Snipto ID' => __('Enter a passphrase to generate your Snipto ID'),
                'Passphrase must be at least 16 characters.' => __('Passphrase must be at least 16 characters.'),
                'Your browser does not support this feature. Please update your browser.' => __('Your browser does not support this feature. Please update your browser.'),
                'Failed to generate Snipto ID. Please try again.' => __('Failed to generate Snipto ID. Please try again.'),
                'Weak passphrase — consider adding more variety or length' => __('Weak passphrase — consider adding more variety or length'),
                'Good passphrase strength' => __('Good passphrase strength'),
            ]);
        @endphp
    </script>
@endsection

@section('content')
    <style @cspNonce>
        [x-cloak] { display: none !important; }
    </style>

    <div class="max-w-xl w-full rounded-xl p-6 space-y-6
            shadow-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100
            transition-colors duration-300"
         x-data="sniptoidComponent()"
         x-init="init()">

        <!-- Browser Support Warning -->
        <div x-show="x25519Supported === false" x-cloak
             class="text-sm text-center text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20 p-4 rounded-lg border border-orange-200 dark:border-orange-800">
            {!! __('Your browser does not support this feature. Please update your browser.') !!}
        </div>

        <!-- Main Content -->
        <div x-show="x25519Supported !== false">
            <div class="text-center space-y-2 mb-6">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                </div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {!! __('Snipto ID') !!}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {!! __('Enter a passphrase to generate your Snipto ID. Others can use this ID to send you encrypted sniptos that only you can decrypt.') !!}
                </p>
            </div>

            <div class="space-y-4">
                <div class="space-y-1">
                    <input type="password" x-model="passphrase"
                           @keydown.enter="deriveSniptoid()"
                           placeholder="{{ __('Enter a passphrase (min 16 characters)') }}"
                           class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100 transition-colors duration-200">
                    <p class="text-xs text-gray-400 dark:text-gray-500 text-right"
                       :class="passphrase.length >= 16 ? 'text-green-500 dark:text-green-400' : ''">
                        <span x-text="passphrase.length"></span> / 16 {{ __('characters minimum') }}
                    </p>
                    <div x-show="passphrase.length >= 16" x-cloak class="mt-1">
                        <div class="flex gap-1 h-1">
                            <div class="flex-1 rounded-full transition-colors duration-300"
                                 :class="passphraseStrength() >= 1 ? 'bg-red-400' : 'bg-gray-200 dark:bg-gray-700'"></div>
                            <div class="flex-1 rounded-full transition-colors duration-300"
                                 :class="passphraseStrength() >= 2 ? 'bg-orange-400' : 'bg-gray-200 dark:bg-gray-700'"></div>
                            <div class="flex-1 rounded-full transition-colors duration-300"
                                 :class="passphraseStrength() >= 3 ? 'bg-yellow-400' : 'bg-gray-200 dark:bg-gray-700'"></div>
                            <div class="flex-1 rounded-full transition-colors duration-300"
                                 :class="passphraseStrength() >= 4 ? 'bg-green-400' : 'bg-gray-200 dark:bg-gray-700'"></div>
                            <div class="flex-1 rounded-full transition-colors duration-300"
                                 :class="passphraseStrength() >= 5 ? 'bg-green-500' : 'bg-gray-200 dark:bg-gray-700'"></div>
                        </div>
                        <p class="text-xs mt-1 text-center"
                           :class="passphraseStrength() < 3 ? 'text-orange-500 dark:text-orange-400' : 'text-green-500 dark:text-green-400'"
                           x-text="passphraseStrength() < 3 ? t('Weak passphrase — consider adding more variety or length') : t('Good passphrase strength')">
                        </p>
                    </div>
                </div>

                <button @click="deriveSniptoid()"
                        :disabled="loading || passphrase.length < 16"
                        class="w-full bg-indigo-500 text-white px-6 py-3 rounded-lg shadow hover:bg-indigo-600 transition transform duration-150 active:scale-[0.98] font-medium disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                    <span x-show="!loading">{!! __('Generate Snipto ID') !!}</span>
                    <span x-show="loading" x-cloak class="flex items-center justify-center space-x-2">
                        <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{!! __('Deriving...') !!}</span>
                    </span>
                </button>
            </div>

            <!-- Snipto ID Result -->
            <div x-show="sniptoId" x-cloak
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 class="mt-6 space-y-3">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 text-center">
                    {!! __('Your Snipto ID') !!}
                </p>
                <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-2 space-y-2 sm:space-y-0">
                    <input type="text" :value="sniptoId" readonly
                           class="w-full border rounded-lg p-3 font-mono text-sm bg-gray-50 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-100 text-center select-all">
                    <button @click="copySniptoId()"
                            class="bg-indigo-500 text-white px-4 py-2 rounded-lg shadow hover:bg-indigo-600 transition transform duration-150 active:scale-95 whitespace-nowrap">
                        {!! __('Copy') !!}
                    </button>
                </div>
                <div class="text-center">
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        {!! __('Fingerprint:') !!}
                        <span x-text="sniptoId ? sniptoId.substring(0, 8) : ''" class="font-mono font-bold text-indigo-500 dark:text-indigo-400"></span>
                    </p>
                </div>
                <div class="text-xs text-center text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/50 p-3 rounded-lg border border-gray-200 dark:border-gray-700 space-y-1">
                    <p>{!! __('Share this Snipto ID with anyone who wants to send you encrypted messages.') !!}</p>
                    <p class="font-medium">{!! __('Remember your passphrase — it is the only way to decrypt messages sent to this ID.') !!}</p>
                </div>
            </div>
        </div>

        <!-- Toast notification -->
        <div x-show="showToast" x-cloak
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-90"
             x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="opacity-100 scale-100"
             x-transition:leave-end="opacity-0 scale-90"
             class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-gray-900/95 dark:bg-gray-100/95 text-white dark:text-gray-900
            px-8 py-4 rounded-2xl shadow-2xl z-50 text-base font-medium whitespace-nowrap border border-white/10 dark:border-black/10 backdrop-blur-sm text-center" x-text="toastMessage">
        </div>
    </div>
@endsection
