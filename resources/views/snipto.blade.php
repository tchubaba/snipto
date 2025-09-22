@extends('layouts.main')

@section('content')

<div class="max-w-xl w-full rounded-xl p-6 space-y-4
                shadow-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100
                transition-colors duration-300"
     x-data="sniptoComponent()"
     x-init="init()">

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

        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="'Expires at: ' + expires_at"></p>
        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="'Views remaining: ' + views_remaining"></p>
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
        <button @click="submitSnipto()"
                class="bg-indigo-500 text-white px-4 py-2 rounded shadow hover:bg-indigo-600
                       hover:shadow-lg transition transform duration-150 active:scale-95">
            Snipto it
        </button>
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
         class="text-red-600 dark:text-red-400 mt-4 font-medium"></div>
</div>

<script>
    /**
     * sniptoComponent
     *
     * This component powers the frontend behavior for Snipto.
     * It handles both creating and viewing encrypted snippets ("sniptos").
     *
     * Key points:
     * - All snippets are encrypted in the browser before sending to the server.
     * - The decryption key never touches the server; it lives only in the URL fragment (#k=...).
     * - AES-CBC is used for encryption, with keys derived via PBKDF2 from a short secret + IV.
     * - The server only stores ciphertext, IV, and metadata (not the key).
     * - Each snippet can expire after a number of views or a certain time.
     */
    function sniptoComponent() {
        return {
            // ------------------------------
            // State variables
            // ------------------------------
            slug: '{{ request()->path() }}'.replace(/^\/+/, ''), // unique identifier for the snippet, taken from URL path
            key: null,          // derived AES key (hex string)
            iv: null,           // initialization vector (hex string)
            payload: '',        // decrypted snippet text
            expires_at: '',     // expiration time returned from server
            views_remaining: 0, // how many times this snippet can still be viewed
            loading: true,      // controls loading spinner
            showPayload: false, // whether to display the decrypted snippet
            showForm: false,    // whether to display the "create snippet" form
            showSuccess: false, // whether to display the "success" state after submission
            errorMessage: '',   // stores any error messages for user feedback
            userInput: '',      // text entered by user for new snippet
            fullUrl: '',        // full sharable URL containing slug + secret
            showToast: false,   // controls "copied to clipboard" toast
            calledInit: false,  // prevents init() from running more than once

            // ------------------------------
            // Initialization logic
            // ------------------------------
            async init() {
                if (this.calledInit) return;
                this.calledInit = true;

                // Enable dark mode automatically if user prefers it
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }

                // If there's no slug in the URL, we are in "create new snippet" mode
                if (!this.slug) {
                    this.showForm = true;
                    this.loading = false;
                    // Automatically focus textarea after a short delay
                    this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
                    return;
                }

                try {
                    // Fetch snippet metadata (ciphertext, IV, etc.) from the server
                    const res = await fetch(`/api/snipto/${this.slug}`, {
                        method: 'GET',
                        headers: { 'Accept': "application/json" }
                    });

                    // If snippet doesn’t exist, show "create new" form
                    if (res.status === 404) {
                        this.showForm = true;
                        this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
                        return;
                    }
                    // If user is rate-limited
                    else if (res.status === 429) {
                        this.errorMessage = 'Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.';
                        return;
                    }

                    if (!res.ok) throw new Error('Error fetching snipto');

                    const data = await res.json();

                    // The short secret is embedded in the URL fragment (#k=...)
                    const shortSecret = new URLSearchParams(window.location.hash.substring(1)).get('k');
                    if (!shortSecret) {
                        this.errorMessage = 'Missing decryption key in URL.';
                        return;
                    }

                    // Derive AES key from short secret + IV
                    this.iv = data.iv;
                    this.key = await this.deriveKey(shortSecret, this.iv);

                    // Attempt to decrypt payload
                    let decrypted;
                    try {
                        decrypted = CryptoJS.AES.decrypt(
                            data.payload,
                            this.key,
                            { iv: CryptoJS.enc.Hex.parse(this.iv) }
                        ).toString(CryptoJS.enc.Utf8);
                    } catch {
                        decrypted = '';
                    }

                    // If decryption fails, show error
                    if (!decrypted) {
                        this.errorMessage = 'Failed to decrypt your snipto. Please check your key.';
                        return;
                    }

                    // Save decrypted text and metadata
                    this.payload = decrypted.trim();
                    this.expires_at = data.expires_at;
                    this.views_remaining = data.views_remaining - 1;
                    this.showPayload = true;

                    // Notify server that snippet was viewed
                    await fetch(`/api/snipto/${this.slug}/viewed`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.getCsrfToken(),
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            payload_hash: CryptoJS.SHA256(data.payload).toString()
                        }),
                        credentials: 'same-origin'
                    });

                } catch(err) {
                    this.errorMessage = err.message;
                } finally {
                    this.loading = false;
                }
            },

            // ------------------------------
            // Submitting a new snippet
            // ------------------------------
            async submitSnipto() {
                if (!this.userInput.trim()) return;

                this.loading = true;

                // 1. Generate a short secret (the actual "key fragment" stored in URL hash)
                const shortSecret = this.generateShortSecret(16);

                // 2. Generate a random IV
                this.iv = await this.generateRandomBytes(16);

                // 3. Derive AES key from secret + IV
                this.key = await this.deriveKey(shortSecret, this.iv);

                // 4. Encrypt user input with AES-CBC
                const encrypted = CryptoJS.AES.encrypt(
                    this.userInput,
                    this.key,
                    { iv: CryptoJS.enc.Hex.parse(this.iv) }
                ).toString();

                try {
                    // Send encrypted payload and IV to server (never the key)
                    const res = await fetch('/api/snipto', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.getCsrfToken(),
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            slug: this.slug,
                            payload: encrypted,
                            iv: this.iv
                        }),
                        credentials: 'same-origin'
                    });

                    // Handle rate limiting
                    if (res.status === 429) {
                        this.errorMessage = 'Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.';
                        return;
                    }

                    const body = await res.json();
                    if (!res.ok || !body.success) {
                        this.errorMessage = 'An error occurred. Please try again.';
                        return;
                    }

                    // Success: show link with embedded key
                    this.showForm = false;
                    this.showSuccess = true;
                    this.fullUrl = `${window.location.origin}/${this.slug}#k=${shortSecret}`;

                    // Generate QR code for quick sharing
                    QRCode.toCanvas(this.$refs.qrcode, this.fullUrl, { width: 128 });

                    // Auto-select the link text for easy copying
                    this.$refs.fullUrlInput.select();

                } catch {
                    this.errorMessage = 'An error occurred. Please try again.';
                } finally {
                    this.loading = false;
                }
            },

            // ------------------------------
            // Secret generator (random alphanumeric)
            // ------------------------------
            generateShortSecret(length) {
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                let result = '';
                const array = new Uint8Array(length);
                window.crypto.getRandomValues(array);
                for (let i = 0; i < length; i++) {
                    result += chars[array[i] % chars.length];
                }
                return result;
            },

            // ------------------------------
            // Derive AES key using PBKDF2
            //
            // We derive a 256-bit AES key from:
            // - the short secret (user-held key fragment)
            // - the IV (used as salt)
            //
            // This makes brute-forcing harder and ensures key uniqueness.
            // ------------------------------
            async deriveKey(secret, ivHex) {
                const enc = new TextEncoder();
                const keyMaterial = await crypto.subtle.importKey(
                    'raw',
                    enc.encode(secret),
                    { name: 'PBKDF2' },
                    false,
                    ['deriveBits', 'deriveKey']
                );

                const derivedKey = await crypto.subtle.deriveKey(
                    {
                        name: 'PBKDF2',
                        salt: enc.encode(ivHex),
                        iterations: 100000,
                        hash: 'SHA-256'
                    },
                    keyMaterial,
                    { name: 'AES-CBC', length: 256 },
                    true,
                    ['encrypt', 'decrypt']
                );

                // Export key as hex string for CryptoJS
                const rawKey = await crypto.subtle.exportKey('raw', derivedKey);
                return Array.from(new Uint8Array(rawKey))
                    .map(b => ('00'+b.toString(16)).slice(-2))
                    .join('');
            },

            // ------------------------------
            // Generate secure random hex string
            // ------------------------------
            async generateRandomBytes(length) {
                const array = new Uint8Array(length);
                window.crypto.getRandomValues(array);
                return Array.from(array)
                    .map(b => ('00'+b.toString(16)).slice(-2))
                    .join('');
            },

            // ------------------------------
            // Copy full URL to clipboard
            // ------------------------------
            copyUrl() {
                navigator.clipboard.writeText(this.fullUrl).then(() => {
                    this.showToast = true;
                    setTimeout(() => this.showToast = false, 2000);
                });
            },

            // ------------------------------
            // Grab CSRF token from page <meta>
            // ------------------------------
            getCsrfToken() {
                return document.querySelector('meta[name="csrf-token"]').content;
            }
        }
    }
</script>

@endsection
