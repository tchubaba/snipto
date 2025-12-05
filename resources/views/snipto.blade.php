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
                'Could not decrypt the Snipto. Decryption failed or data tampered.' => __('Could not decrypt the Snipto. Decryption failed or data tampered.')
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
            <!-- Display payload -->
            <div x-show="showPayload" x-cloak
                 x-transition.opacity.duration.500ms
                 class="space-y-4 transform transition-all duration-300 hover:scale-[1.01]">
                <p class="text-lg font-medium text-gray-700 dark:text-gray-300">
                    {!! __('Here’s your snipto:') !!}
                </p>
                <p x-show="!isPayloadEncrypted" x-cloak
                   class="text-xs text-center text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20 p-3 rounded border border-orange-200 dark:border-orange-800 w-full">
                    {!! __('Notice: This text was shared without end-to-end encryption. It was sent and stored unencrypted, and could be read while stored on the server.') !!}
                </p>
                <div id="snipto-payload-container"
                     class="p-4 border-l-4 border-indigo-500 bg-indigo-50 dark:bg-indigo-800 rounded shadow-sm break-words whitespace-pre-wrap h-auto min-h-[50px] max-h-[calc(100vh-20rem)] overflow-auto">
                </div>
                <p x-ref="sniptoDisplayFooterRef"
                   x-text="sniptoDisplayFooter"
                   :class="footerColorClass"
                   class="mt-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                    {!! __('If you want to keep this Snipto, copy and paste it elsewhere. It has now been deleted from our servers and cannot be viewed again.') !!}
                </p>
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

                <div class="flex flex-col items-center space-y-3 w-full max-w-md mx-auto">
                    <div class="flex items-center justify-center space-x-3 cursor-pointer" @click="encryptSnipto = !encryptSnipto">
                        <div class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2"
                             :class="encryptSnipto ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700'">
                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                      :class="encryptSnipto ? 'translate-x-5' : 'translate-x-0'"></span>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 select-none">
                                {!! __('Use end-to-end encryption') !!}
                            </span>
                    </div>

                    <p x-show="!encryptSnipto" x-cloak x-transition.opacity
                       class="text-xs text-center text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20 p-3 rounded border border-orange-200 dark:border-orange-800 w-full">
                        {!! __('Warning: Turning off end-to-end encryption means your text will be sent in plain text and will be readable while stored on the server.') !!}
                    </p>
                </div>

                <div class="flex flex-col items-center space-y-2">
                    <button @click="submitSnipto()"
                            class="bg-indigo-500 text-white px-6 py-2 rounded shadow hover:bg-indigo-600
                           hover:shadow-lg transition transform duration-150 active:scale-95">
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
                <p class="text-sm text-gray-500 dark:text-gray-400 text-center">
                    {!! __('Your Snipto will be available for retrieval for the next hour. After that, it will be deleted automatically.') !!}
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
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4"
             class="fixed bottom-4 right-4 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900
            px-4 py-2 rounded shadow-lg z-50 text-sm">
            Copied to clipboard!
        </div>
    </div>
@endsection
