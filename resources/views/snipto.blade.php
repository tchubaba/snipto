@extends('layouts.main')

@section('header-js')
    <script type="module" src="{{ asset('build/js/snipto.js') }}?v={{ filemtime(public_path('build/js/snipto.js')) }}"></script>
@endsection

@section('alpine-translations')
    <script @cspNonce>
        window.i18n =
        @php
            echo json_encode([
                'Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.' => __('Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.'),
                'We can’t open this Snipto. The encryption key is missing in the URL.' => __('We can’t open this Snipto. The encryption key is missing in the URL.'),
                'We cannot open this Snipto. It appears the encryption key is invalid.' => __('We cannot open this Snipto. It appears the encryption key is invalid.'),
                'WARNING: The automatic deletion of this snipto failed! This snipto will remain visible until it expires (1 week after creation).' => __('WARNING: The automatic deletion of this snipto failed! This snipto will remain visible until it expires (1 week after creation).'),
                'ATTENTION: This snipto was configured to be viewed more than 1 time. It can still be viewed :count more times.' => __('ATTENTION: This snipto was configured to be viewed more than 1 time. It can still be viewed :count more times.'),
                'An error occurred. Please try again.' => __('An error occurred. Please try again.'),
                'Failed to render snipto content. Please copy it manually.' => __('Failed to render snipto content. Please copy it manually.'),
                'Failed to find display element.' => __('Failed to find display element.'),
                'Could not decrypt the Snipto. Decryption failed or data tampered.' => __('Could not decrypt the Snipto. Decryption failed or data tampered.'),
                'Copied to clipboard!' => __('Copied to clipboard!'),
                'Copying failed. Please copy manually.' => __('Copying failed. Please copy manually.'),
                'This snippet is protected by a password.' => __('This snippet is protected by a password.'),
                'Unlock' => __('Unlock'),
                'Enter password' => __('Enter password'),
                'Invalid password. Please try again.' => __('Invalid password. Please try again.'),
                'This snippet is unavailable or the link is invalid.' => __('This snippet is unavailable or the link is invalid.'),
                'Password must be at least 8 characters long.' => __('Password must be at least 8 characters long.'),
                'You attempted too many times. Please try again in :seconds seconds.' => __('You attempted too many times. Please try again in :seconds seconds.'),
                'Expire after:' => __('Expire after:'),
                '1 Hour' => __('1 Hour'),
                '1 Day' => __('1 Day'),
                '1 Week' => __('1 Week'),
                'This snippet will expire in 1 hour.' => __('This snippet will expire in 1 hour.'),
                'Caution: Weak Password Detected' => __('Caution: Weak Password Detected'),
                'This password is easy to guess, which makes the encryption much easier to break. For better security, we recommend a longer or more varied password.' => __('This password is easy to guess, which makes the encryption much easier to break. For better security, we recommend a longer or more varied password.'),
                'Go back and change' => __('Go back and change'),
                'Use anyway' => __('Use anyway'),
                'Please enter a Snipto ID.' => __('Please enter a Snipto ID.'),
                'Invalid Snipto ID format.' => __('Invalid Snipto ID format.'),
                'This snippet was sent to a Snipto ID.' => __('This snippet was sent to a Snipto ID.'),
                'Enter your passphrase to generate your Snipto ID and decrypt.' => __('Enter your passphrase to generate your Snipto ID and decrypt.'),
                'Decrypt' => __('Decrypt'),
                'Decryption failed. Please check your passphrase and try again.' => __('Decryption failed. Please check your passphrase and try again.'),
                'Passphrase must be at least 16 characters.' => __('Passphrase must be at least 16 characters.'),
                'Your browser does not support this feature. Please update your browser.' => __('Your browser does not support this feature. Please update your browser.'),
                'You have been temporarily locked out. Please check back later.' => __('You have been temporarily locked out. Please check back later.')
            ]);
        @endphp
    </script>
@endsection

@section('content')
    <style @cspNonce>
        [x-cloak] { display: none !important; }
    </style>

    <div class="max-w-4xl w-full rounded-xl p-6 space-y-4
            shadow-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100
            transition-colors duration-300"
         x-data="sniptoComponent()"
         x-init="init()"
         data-slug="{{ request()->path() }}"
         x-on:before-unmount.window="destroy()"
    >
        <!-- Loader -->
        <div x-show="loading"
             x-transition.opacity
             class="fixed inset-0 bg-gray-200/50 dark:bg-gray-900/50 flex justify-center items-center z-40">
            <div class="flex justify-center items-center space-x-2 bg-gray-100 dark:bg-gray-800 p-4 rounded-xl shadow-lg">
                <svg class="animate-spin h-6 w-6 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span class="text-gray-500 dark:text-gray-400">{!! __('Loading...') !!}</span>
            </div>
        </div>

        <!-- Content Wrapper: Hide until loading completes -->
        <div x-show="!loading" x-cloak x-transition:enter="transition ease-out duration-500"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="space-y-4">

            <!-- Lockout Notice -->
            <div x-show="isLockedOut" x-cloak x-transition.opacity
                 class="text-sm text-center text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 p-4 rounded border border-red-200 dark:border-red-800 w-full">
                <span x-text="t('You have been temporarily locked out. Please check back later.')"></span>
            </div>

            <!-- Throttle Countdown (top-level: metadata-fetch throttle, outside of form/prompt contexts) -->
            <div x-show="isThrottled && !showPasswordPrompt && !showSniptoIdPrompt && !showForm" x-cloak x-transition.opacity
                 class="text-xs text-center text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 p-3 rounded border border-red-200 dark:border-red-800 w-full">
                <span x-text="t('You attempted too many times. Please try again in :seconds seconds.', { ':seconds': throttleCountdown })"></span>
            </div>

            <!-- Display payload -->
            <div x-show="showPayload" x-cloak
                 class="space-y-4 transform transition-transform duration-300 hover:scale-[1.01]">
                <div class="flex justify-between items-start">
                    <p class="text-lg font-medium text-gray-700 dark:text-gray-300">
                        {!! __('Here’s your snipto:') !!}
                    </p>
                    <button @click="copyToClipboard($event)"
                            class="bg-indigo-500 text-white px-3 py-1 rounded text-sm shadow hover:bg-indigo-600
                               hover:shadow-md transition transform duration-150 active:scale-95">
                        {!! __('Copy') !!}
                    </button>
                </div>
                <p x-show="!isPayloadEncrypted" x-cloak
                   class="text-xs text-center text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20 p-3 rounded border border-orange-200 dark:border-orange-800 w-full">
                    {!! __('Notice: This text was shared without end-to-end encryption. It was sent and stored unencrypted, and could be read while stored on the server.') !!}
                </p>
                <div id="snipto-payload-container"
                     class="p-4 border-l-4 border-indigo-500 bg-indigo-50 dark:bg-indigo-800 rounded shadow-sm break-words whitespace-pre-wrap h-auto min-h-[50px] max-h-[calc(100vh-20rem)] overflow-auto">
                </div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                    {!! __('If you want to keep this Snipto, copy and paste it elsewhere. It has now been deleted from our servers and cannot be viewed again.') !!}
                </p>
            </div>

            <!-- Password Retrieval Prompt -->
            <div x-show="showPasswordPrompt" x-cloak
                 class="space-y-6 max-w-md mx-auto py-8">
                <div class="text-center space-y-2">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 mb-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {!! __('This snippet is protected by a password.') !!}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {!! __('Enter the password to decrypt and view the content.') !!}
                    </p>
                </div>

                <div class="space-y-4">
                    <!-- Throttling Warning -->
                    <div x-show="isThrottled" x-cloak x-transition.opacity
                         class="text-xs text-center text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 p-3 rounded border border-red-200 dark:border-red-800 w-full mb-4">
                        <span x-text="t('You attempted too many times. Please try again in :seconds seconds.', { ':seconds': throttleCountdown })"></span>
                    </div>

                    <input type="password" x-model="protectionPassword"
                           @keydown.enter="unlockWithPassword()"
                           :disabled="isThrottled || isLockedOut"
                           placeholder="{{ __('Enter password') }}"
                           class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100 transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed">
                    
                    <button @click="unlockWithPassword()"
                            :disabled="isThrottled || isLockedOut || !protectionPassword.trim()"
                            class="w-full bg-indigo-500 text-white px-6 py-3 rounded-lg shadow hover:bg-indigo-600 transition transform duration-150 active:scale-[0.98] font-medium disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                        {!! __('Unlock') !!}
                    </button>
                </div>
            </div>

            <!-- Snipto ID Retrieval Prompt -->
            <div x-show="showSniptoIdPrompt" x-cloak
                 class="space-y-6 max-w-md mx-auto py-8">
                <div class="text-center space-y-2">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 mb-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {!! __('This snippet was sent to a Snipto ID.') !!}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {!! __('Enter your passphrase to generate your Snipto ID and decrypt.') !!}
                    </p>
                </div>

                <div class="space-y-4">
                    <!-- Throttling Warning -->
                    <div x-show="isThrottled" x-cloak x-transition.opacity
                         class="text-xs text-center text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 p-3 rounded border border-red-200 dark:border-red-800 w-full mb-4">
                        <span x-text="t('You attempted too many times. Please try again in :seconds seconds.', { ':seconds': throttleCountdown })"></span>
                    </div>

                    <input type="password" x-model="sniptoIdPassphrase"
                           @keydown.enter="unlockWithSniptoId()"
                           :disabled="isThrottled || isLockedOut"
                           placeholder="{{ __('Enter your passphrase') }}"
                           class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100 transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed">

                    <button @click="unlockWithSniptoId()"
                            :disabled="isThrottled || isLockedOut || !sniptoIdPassphrase.trim()"
                            class="w-full bg-indigo-500 text-white px-6 py-3 rounded-lg shadow hover:bg-indigo-600 transition transform duration-150 active:scale-[0.98] font-medium disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                        {!! __('Decrypt') !!}
                    </button>
                </div>
            </div>

            <!-- Create new snipto -->
            <div x-show="showForm" x-cloak
                 x-transition.opacity.duration.500ms
                 x-transition.scale.origin.top
                 class="space-y-4 transform transition-all duration-300">
                <p class="text-lg font-medium text-gray-700 dark:text-gray-300">
                    {!! __('Got something to share?') !!}
                </p>
                <textarea x-model="userInput" rows="5" placeholder="{!! __('Type or paste your text here') !!}"
                          x-ref="textarea"
                          class="w-full border rounded p-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none
                         dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100 transition-colors duration-200
                         placeholder-gray-400 dark:placeholder-gray-500"></textarea>

                <div class="flex flex-col items-center space-y-4 w-full max-w-2xl mx-auto">
                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 w-full">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400 whitespace-nowrap">
                            {!! __('Secure with') !!}
                        </p>
                        <!-- Mode Switcher -->
                        <div class="flex p-1 bg-gray-100 dark:bg-gray-900 rounded-xl w-full max-w-lg border border-gray-200 dark:border-gray-700">
                            <!-- Mode 1: Random URL Secret Key -->
                            <button @click="protectionType = 1" 
                                    :class="protectionType === 1 ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                                    class="flex-1 py-2 px-2 rounded-lg text-xs sm:text-sm font-medium transition-all duration-200">
                                {{ __('Random URL Secret Key') }}
                            </button>

                            <!-- Mode 3: Snipto ID -->
                            <button x-show="x25519Supported" @click="protectionType = 3"
                                    :class="protectionType === 3 ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                                    class="flex-1 py-2 px-2 rounded-lg text-xs sm:text-sm font-medium transition-all duration-200">
                                {{ __('Snipto ID') }}
                            </button>

                            <!-- Mode 2: Password -->
                            <button @click="protectionType = 2" 
                                    :class="protectionType === 2 ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                                    class="flex-1 py-2 px-2 rounded-lg text-xs sm:text-sm font-medium transition-all duration-200">
                                {{ __('Password') }}
                            </button>

                            <!-- Mode 0: E2EE Disabled -->
                            <button @click="protectionType = 0"
                                    :class="protectionType === 0 ? 'bg-white dark:bg-gray-700 shadow-sm text-orange-600 dark:text-orange-400' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                                    class="flex-1 py-2 px-2 rounded-lg text-xs sm:text-sm font-medium transition-all duration-200">
                                {{ __('E2EE disabled') }}
                            </button>
                        </div>
                    </div>

                    <!-- Conditional Content -->
                    <div class="w-full grid transition-all duration-300 ease-in-out" 
                         :class="protectionType != 1 ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[1fr] opacity-100'">
                        <div class="overflow-hidden">
                            <!-- Expiration Info for non-password/non-sniptoid modes -->
                            <div x-show="protectionType != 2 && protectionType != 3" x-cloak
                                 class="flex items-center justify-center space-x-2 py-2 text-gray-500 dark:text-gray-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span class="text-xs font-medium">{!! __('This snippet will expire in 1 hour.') !!}</span>
                            </div>

                            <!-- Password Input -->
                            <div x-show="protectionType === 2" x-cloak 
                                 x-transition:enter="transition ease-out duration-300 delay-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 class="pt-4 space-y-4">
                                <div class="flex items-center justify-center space-x-3">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        {!! __('Expire after:') !!}
                                    </p>
                                    <div class="flex p-0.5 bg-gray-100 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                                        <button @click="expirationValue = '1h'" 
                                                type="button"
                                                :class="expirationValue === '1h' ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500'"
                                                class="py-1 px-2 rounded-md text-[10px] uppercase tracking-wider font-bold transition-all duration-200">
                                            {!! __('1 Hour') !!}
                                        </button>
                                        <button @click="expirationValue = '1d'" 
                                                type="button"
                                                :class="expirationValue === '1d' ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500'"
                                                class="py-1 px-2 rounded-md text-[10px] uppercase tracking-wider font-bold transition-all duration-200">
                                            {!! __('1 Day') !!}
                                        </button>
                                        <button @click="expirationValue = '1w'" 
                                                type="button"
                                                :class="expirationValue === '1w' ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500'"
                                                class="py-1 px-2 rounded-md text-[10px] uppercase tracking-wider font-bold transition-all duration-200">
                                            {!! __('1 Week') !!}
                                        </button>
                                    </div>
                                </div>

                                <input type="password" x-model="protectionPassword" 
                                       x-ref="protectionPasswordField"
                                       placeholder="{{ __('Enter a password to protect this snippet (min 8 chars)') }}"
                                       class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100 transition-colors duration-200">
                            </div>

                            <!-- Snipto ID Input -->
                            <div x-show="protectionType === 3" x-cloak
                                 x-transition:enter="transition ease-out duration-300 delay-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 class="pt-4 space-y-4">
                                <div class="flex items-center justify-center space-x-3">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        {!! __('Expire after:') !!}
                                    </p>
                                    <div class="flex p-0.5 bg-gray-100 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                                        <button @click="expirationValue = '1h'"
                                                type="button"
                                                :class="expirationValue === '1h' ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500'"
                                                class="py-1 px-2 rounded-md text-[10px] uppercase tracking-wider font-bold transition-all duration-200">
                                            {!! __('1 Hour') !!}
                                        </button>
                                        <button @click="expirationValue = '1d'"
                                                type="button"
                                                :class="expirationValue === '1d' ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500'"
                                                class="py-1 px-2 rounded-md text-[10px] uppercase tracking-wider font-bold transition-all duration-200">
                                            {!! __('1 Day') !!}
                                        </button>
                                        <button @click="expirationValue = '1w'"
                                                type="button"
                                                :class="expirationValue === '1w' ? 'bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400' : 'text-gray-500'"
                                                class="py-1 px-2 rounded-md text-[10px] uppercase tracking-wider font-bold transition-all duration-200">
                                            {!! __('1 Week') !!}
                                        </button>
                                    </div>
                                </div>

                                <input type="text" x-model="sniptoIdInput"
                                       placeholder="{{ __('Recipient\'s Snipto ID') }}"
                                       class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100 transition-colors duration-200 font-mono text-sm">
                                
                                <div class="text-xs text-center text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/30 p-3 rounded border border-gray-200 dark:border-gray-800">
                                    {!! __('Important: Double-check the recipient’s Snipto ID. If you enter it incorrectly, they will not be able to decrypt or read your message.') !!}
                                </div>
                            </div>

                            <!-- Warning Box for Disabled E2EE -->
                            <div x-show="protectionType === 0" x-cloak
                                 x-transition:enter="transition ease-out duration-300 delay-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 class="pt-4">
                                <div class="text-xs text-center text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20 p-3 rounded border border-orange-200 dark:border-orange-800 w-full">
                                    {!! __('Notice: This option disables end-to-end encryption! Your snippet will be sent and stored in plaintext. This means it could be read by anyone that intercepts it or has database access.') !!}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col items-center space-y-2">
                    <!-- Throttling Warning (creation form) -->
                    <div x-show="isThrottled || isLockedOut" x-cloak x-transition.opacity
                         class="text-xs text-center text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 p-3 rounded border border-red-200 dark:border-red-800 w-full">
                        <span x-show="isThrottled" x-text="t('You attempted too many times. Please try again in :seconds seconds.', { ':seconds': throttleCountdown })"></span>
                        <span x-show="isLockedOut" x-text="t('You have been temporarily locked out. Please check back later.')"></span>
                    </div>
                    <button @click="submitSnipto()"
                            :disabled="isThrottled || isLockedOut"
                            class="bg-indigo-500 text-white px-6 py-2 rounded shadow hover:bg-indigo-600
                           hover:shadow-lg transition transform duration-150 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none">
                        {!! __('Snipto it') !!}
                    </button>
                    <!-- Terms of Service notice -->
                    <p class="text-xs text-gray-500 dark:text-gray-400 text-center">
                        {!! __('By using Snipto, you agree with the') !!} <a href="/terms" class="underline hover:text-indigo-500 dark:hover:text-indigo-400">{!! __('Terms of Service') !!}</a>.
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 text-center">
                        {{ __('Keep your data safe by following our') }} <a href="/safety" class="underline hover:text-indigo-500 dark:hover:text-indigo-400">{{ __('safety tips') }}</a>.
                    </p>
                </div>
            </div>

            <!-- Success info -->
            <div x-show="showSuccess" x-cloak
                 x-transition.opacity.duration.500ms
                 x-transition.scale.origin.top
                 class="space-y-4 transform transition-all duration-300">
                <p class="text-green-600 dark:text-green-400 font-medium">{!! __('Here’s your Snipto:') !!}</p>
                <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-2 space-y-2 sm:space-y-0">
                    <input type="text" :value="fullUrl" readonly
                           x-ref="fullUrlInput"
                           class="w-full border rounded p-2 focus:ring-2 focus:ring-indigo-400 focus:outline-none
                              dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100">
                    <button @click="copyUrl()"
                            class="bg-indigo-500 text-white px-3 py-1 rounded shadow hover:bg-indigo-600
                               hover:shadow-md transition transform duration-150 active:scale-95">
                        {!! __('Copy') !!}
                    </button>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center" x-show="expirationValue === '1h'">
                    {!! __('Your Snipto will be available for retrieval for the next hour. After that, it will be deleted automatically.') !!}
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center" x-show="expirationValue === '1d'" x-cloak>
                    {!! __('Your Snipto will be available for retrieval for the next day. After that, it will be deleted automatically.') !!}
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center" x-show="expirationValue === '1w'" x-cloak>
                    {!! __('Your Snipto will be available for retrieval for the next week. After that, it will be deleted automatically.') !!}
                </p>
                <canvas x-ref="qrcode" class="rounded shadow-sm"></canvas>
            </div>

            <!-- Error -->
            <div x-show="errorMessage" x-cloak
                 x-transition.opacity.duration.300ms
                 x-text="errorMessage"
                 class="text-red-600 dark:text-red-400 mt-4 font-medium">
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

        <!-- Weak Password Warning Modal -->
        <div x-show="showWeakPasswordModal" x-cloak
             class="fixed inset-0 z-[60] overflow-y-auto"
             aria-labelledby="modal-title" role="dialog" aria-modal="true">
            
            <!-- Backdrop -->
            <div x-show="showWeakPasswordModal" 
                 x-transition:enter="ease-out duration-300" 
                 x-transition:enter-start="opacity-0" 
                 x-transition:enter-end="opacity-100" 
                 x-transition:leave="ease-in duration-200" 
                 x-transition:leave-start="opacity-100" 
                 x-transition:leave-end="opacity-0" 
                 class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/80 transition-opacity backdrop-blur-sm" 
                 aria-hidden="true"></div>

            <!-- Centering container -->
            <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:p-0">
                <span class="hidden sm:inline-block sm:align-middle sm:min-h-screen" aria-hidden="true">&#8203;</span>

                <!-- Modal Content -->
                <div x-show="showWeakPasswordModal" 
                     x-transition:enter="ease-out duration-300" 
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" 
                     x-transition:leave="ease-in duration-200" 
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" 
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     class="relative inline-block align-bottom bg-white dark:bg-gray-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-gray-200 dark:border-gray-700 z-10">
                    <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-orange-100 dark:bg-orange-900/30 sm:mx-0 sm:h-10 sm:w-10">
                                <svg class="h-6 w-6 text-orange-600 dark:text-orange-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                <h3 class="text-lg leading-6 font-bold text-gray-900 dark:text-gray-100" id="modal-title">
                                    {!! __('Caution: Weak Password Detected') !!}
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {!! __('This password is easy to guess, which makes the encryption much easier to break. For better security, we recommend a longer or more varied password.') !!}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-800/50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                        <button type="button" @click="closeWeakPasswordModal()" class="w-full inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:w-auto sm:text-sm transition-all duration-200">
                            {!! __('Go back and change') !!}
                        </button>
                        <button type="button" @click="submitSnipto(true)" class="mt-3 w-full inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-700 text-base font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm transition-all duration-200">
                            {!! __('Use anyway') !!}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
