@extends('layouts.header')

@section('title', 'Snipto')

<div class="max-w-xl w-full bg-white rounded-xl shadow-lg p-6 space-y-4"
     x-data="sniptoComponent()"
     x-init="init()">

    <!-- Loader -->
    <div x-show="loading" class="flex justify-center items-center space-x-2">
        <svg class="animate-spin h-6 w-6 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <span class="text-gray-500">Loading...</span>
    </div>

    <!-- Display payload -->
    <div x-show="showPayload" x-transition.opacity.duration.500ms class="space-y-4">
        <div class="p-4 bg-indigo-50 border-l-4 border-indigo-500 rounded">
            <p x-text="payload" class="text-gray-800 break-words"></p>
        </div>
        <p class="text-sm text-gray-500" x-text="'Expires at: ' + expires_at"></p>
        <p class="text-sm text-gray-500" x-text="'Views remaining: ' + views_remaining"></p>
    </div>

    <!-- Create new snipto -->
    <div x-show="showForm" x-transition.opacity.duration.500ms class="space-y-4">
        <textarea x-model="userInput" rows="5" placeholder="Type or paste your text here"
                  class="w-full border rounded p-2 focus:ring-2 focus:ring-indigo-400 focus:outline-none transition"></textarea>
        <button @click="submitSnipto()"
                class="bg-indigo-500 text-white px-4 py-2 rounded hover:bg-indigo-600 transition">
            Submit
        </button>
    </div>

    <!-- Success info -->
    <div x-show="showSuccess" x-transition.opacity.duration.500ms class="space-y-4">
        <p class="text-green-600">Snipto created successfully!</p>
        <div class="flex items-center space-x-2">
            <input type="text" :value="fullUrl" readonly
                   x-ref="fullUrlInput"
                   class="w-full border rounded p-2 focus:ring-2 focus:ring-indigo-400 focus:outline-none">
            <button @click="copyUrl()"
                    class="bg-indigo-500 text-white px-3 py-1 rounded hover:bg-indigo-600 transition">
                Copy
            </button>
        </div>
        <canvas x-ref="qrcode"></canvas>
    </div>

    <!-- Toast notification -->
    <div x-show="showToast" x-transition.opacity.duration.300ms
         class="fixed bottom-4 right-4 bg-gray-900 text-white px-4 py-2 rounded shadow">
        Copied to clipboard!
    </div>

    <!-- Error -->
    <div x-show="errorMessage" x-text="errorMessage" class="text-red-600 mt-4"></div>
</div>

<script>
    function sniptoComponent() {
        return {
            slug: '{{ request()->path() }}'.replace(/^\/+/,''),
            key: null,
            iv: null,
            payload: '',
            expires_at: '',
            views_remaining: 0,
            loading: true,
            showPayload: false,
            showForm: false,
            showSuccess: false,
            errorMessage: '',
            userInput: '',
            fullUrl: '',
            showToast: false,

            async init() {
                if (!this.slug) {
                    this.showForm = true;
                    this.loading = false;
                    return;
                }

                try {
                    const res = await fetch(`/api/snipto/${this.slug}`);
                    if (res.status === 404) {
                        this.showForm = true;
                        return;
                    }
                    if (!res.ok) throw new Error('Error fetching snipto');

                    const data = await res.json();
                    this.key = new URLSearchParams(window.location.hash.substring(1)).get('k');
                    if (!this.key) {
                        this.errorMessage = 'Missing decryption key in URL.';
                        return;
                    }

                    const decrypted = CryptoJS.AES.decrypt(data.payload, this.key, { iv: CryptoJS.enc.Hex.parse(data.iv) }).toString(CryptoJS.enc.Utf8);
                    this.payload = decrypted;
                    this.iv = data.iv;
                    this.expires_at = data.expires_at;
                    this.views_remaining = data.views_remaining;
                    this.showPayload = true;

                    // Mark as viewed
                    await fetch(`/api/snipto/${this.slug}/viewed`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ payload_hash: CryptoJS.SHA256(decrypted).toString() })
                    });
                } catch(err) {
                    this.errorMessage = err.message;
                } finally {
                    this.loading = false;
                }
            },

            async generateRandomBytes(length) {
                const array = new Uint8Array(length);
                window.crypto.getRandomValues(array);
                return Array.from(array).map(b => ('00'+b.toString(16)).slice(-2)).join('');
            },

            async submitSnipto() {
                if (!this.userInput.trim()) return;

                this.loading = true;
                this.key = await this.generateRandomBytes(32); // 256-bit key
                this.iv = await this.generateRandomBytes(16);  // 128-bit IV

                const encrypted = CryptoJS.AES.encrypt(this.userInput, this.key, { iv: CryptoJS.enc.Hex.parse(this.iv) }).toString();

                try {
                    const res = await fetch('/api/snipto', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            slug: this.slug,
                            payload: encrypted,
                            iv: this.iv
                        })
                    });

                    const body = await res.json();
                    if (!res.ok || !body.success) {
                        this.errorMessage = 'An error occurred. Please try again.';
                        return;
                    }

                    this.showForm = false;
                    this.showSuccess = true;
                    this.fullUrl = `${window.location.origin}/${this.slug}#k=${this.key}`;

                    QRCode.toCanvas(this.$refs.qrcode, this.fullUrl, { width: 128 });

                    // Auto-select input
                    this.$refs.fullUrlInput.select();
                } catch {
                    this.errorMessage = 'An error occurred. Please try again.';
                } finally {
                    this.loading = false;
                }
            },

            copyUrl() {
                navigator.clipboard.writeText(this.fullUrl).then(() => {
                    this.showToast = true;
                    setTimeout(() => this.showToast = false, 2000);
                });
            }
        }
    }
</script>

@extends('layouts.footer')
