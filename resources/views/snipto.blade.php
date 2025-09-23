@extends('layouts.main')

@section('content')

    <div class="max-w-4xl w-full rounded-xl p-6 space-y-4
            shadow-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100
            transition-colors duration-300"
         x-data="sniptoComponent()"
         x-init="init()"
         data-slug="{{ request()->path() }}"
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
                <span class="text-gray-500 dark:text-gray-400">Loading...</span>
            </div>
        </div>

        <!-- Display payload -->
        <div x-show="showPayload"
             x-transition.opacity.duration.500ms
             class="space-y-4 transform transition-all duration-300 hover:scale-[1.01]">

            <p class="text-lg font-medium text-gray-700 dark:text-gray-300">
                Here's your snipto:
            </p>
            <div class="p-4 border-l-4 border-indigo-500 bg-indigo-50 dark:bg-indigo-800 rounded shadow-sm
                    break-words whitespace-pre-wrap"
                 x-text="payload">
            </div>
        </div>

        <!-- Create new snipto -->
        <div x-show="showForm"
             x-transition.opacity.duration.500ms
             x-transition.scale.origin.top
             class="space-y-4 transform transition-all duration-300">
            <p class="text-lg font-medium text-gray-700 dark:text-gray-300">
                Got something to share?
            </p>
            <textarea x-model="userInput" rows="5" placeholder="Type or paste your text here"
                      x-ref="textarea"
                      class="w-full border rounded p-3 focus:ring-2 focus:ring-indigo-400 focus:outline-none
                     dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100 transition-colors duration-200
                     placeholder-gray-400 dark:placeholder-gray-500"></textarea>

            <div class="flex flex-col items-center space-y-2">
                <button @click="submitSnipto()"
                        class="bg-indigo-500 text-white px-6 py-2 rounded shadow hover:bg-indigo-600
                       hover:shadow-lg transition transform duration-150 active:scale-95">
                    Snipto it
                </button>

                <!-- Terms of Service notice -->
                <p class="text-xs text-gray-500 dark:text-gray-400 text-center">
                    By using Snipto, you agree with the <a href="/terms" class="underline hover:text-indigo-500 dark:hover:text-indigo-400">Terms of Service</a>.
                </p>
            </div>
        </div>

        <!-- Success info -->
        <div x-show="showSuccess"
             x-transition.opacity.duration.500ms
             x-transition.scale.origin.top
             class="space-y-4 transform transition-all duration-300">
            <p class="text-green-600 dark:text-green-400 font-medium">Here's your Snipto:</p>
            <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-2 space-y-2 sm:space-y-0">
                <input type="text" :value="fullUrl" readonly
                       x-ref="fullUrlInput"
                       class="w-full border rounded p-2 focus:ring-2 focus:ring-indigo-400 focus:outline-none
                          dark:bg-gray-800 dark:border-gray-700 dark:text-gray-100">
                <button @click="copyUrl()"
                        class="bg-indigo-500 text-white px-3 py-1 rounded shadow hover:bg-indigo-600
                           hover:shadow-md transition transform duration-150 active:scale-95">
                    Copy
                </button>
            </div>
            <canvas x-ref="qrcode" class="rounded shadow-sm"></canvas>
        </div>

        <!-- Toast notification -->
        <div x-show="showToast"
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

        <!-- Error -->
        <div x-show="errorMessage"
             x-transition.opacity.duration.300ms
             x-text="errorMessage"
             class="text-red-600 dark:text-red-400 mt-4 font-medium">
        </div>

    </div>

@endsection
