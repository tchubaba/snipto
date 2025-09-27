export function sniptoComponent() {
    return {
        slug: null,
        key: null,
        hmacKey: null,
        plaintextHmacKey: null,
        nonce: null,
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
        calledInit: false,
        sniptoDisplayFooter: null,
        footerColorClass: '',

        async init() {
            this.slug = this.$el.dataset.slug || '';
            if (this.calledInit) return;
            this.calledInit = true;

            // Initialize Trusted Types policy for srcdoc
            if (window.trustedTypes && trustedTypes.createPolicy) {
                this.trustedTypesPolicy = trustedTypes.createPolicy('snipto-srcdoc', {
                    createHTML: (input) => input // Input is already escaped via escapeHtml
                });
            }

            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }

            if (!this.slug) {
                this.showForm = true;
                this.loading = false;
                this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
                return;
            }

            if (this.sniptoDisplayFooter === null && this.$refs.sniptoDisplayFooterRef) {
                this.sniptoDisplayFooter = this.$refs.sniptoDisplayFooterRef.textContent.trim();
            }

            if (!this.footerColorClass && this.$refs.sniptoDisplayFooterRef) {
                this.footerColorClass = this.$refs.sniptoDisplayFooterRef.className;
            }

            const shortSecret = new URLSearchParams(window.location.hash.substring(1)).get('k');

            if (!shortSecret) {
                try {
                    const res = await fetch(`/api/snipto/${this.slug}`, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' }
                    });

                    if (res.status === 404) {
                        this.showForm = true;
                        this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
                    } else if (res.status === 429) {
                        this.errorMessage = this.t('Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.');
                    } else if (res.status === 200) {
                        const data = await res.json();
                        if (data.exists) {
                            this.errorMessage = this.t('We can’t open this Snipto. The encryption key is missing in the URL.');
                        } else {
                            this.showForm = true;
                            this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
                        }
                    } else {
                        this.errorMessage = this.t('An error occurred. Please try again.');
                    }
                } catch {
                    this.errorMessage = this.t('An error occurred. Please try again.');
                } finally {
                    this.loading = false;
                }
                return;
            }

            try {
                const secretHash = await this.sha256Hex(new TextEncoder().encode(shortSecret));
                const res = await fetch(`/api/snipto/${this.slug}?key_hash=${secretHash}`, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });

                if (res.status === 404) {
                    this.showForm = true;
                    this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
                    return;
                } else if (res.status === 429) {
                    this.errorMessage = this.t('Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.');
                    return;
                } else if (res.status === 403) {
                    this.errorMessage = this.t('We cannot open this Snipto. It appears the encryption key is invalid.');
                    return;
                }

                if (!res.ok) throw new Error(this.t('An error occurred. Please try again.'));

                const data = await res.json();

                this.nonce = data.nonce;
                const { encKey, hmacKey, plaintextHmacKey } = await this.deriveKeys(shortSecret, this.nonce);
                this.key = encKey;
                this.hmacKey = hmacKey;
                this.plaintextHmacKey = plaintextHmacKey;

                let decrypted;
                try {
                    decrypted = await this.decryptPayload(data.payload, this.key, this.nonce, this.hmacKey);
                } catch {
                    this.errorMessage = this.t('Could not decrypt the Snipto. Decryption failed or data tampered.');
                    return;
                }

                const plaintextHmac = await crypto.subtle.sign(
                    { name: 'HMAC' },
                    this.plaintextHmacKey,
                    new TextEncoder().encode(decrypted)
                );
                const plaintextHmacHex = Array.from(new Uint8Array(plaintextHmac))
                    .map(b => b.toString(16).padStart(2, '0'))
                    .join('');

                const decryptedText = decrypted.trim();

                // Render payload
                this.renderPayload(decryptedText);

                this.expires_at = data.expires_at;
                this.views_remaining = data.views_remaining - 1;
                this.showPayload = true;

                // Notify server of view
                const viewed = await fetch(`/api/snipto/${this.slug}/viewed`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ plaintext_hmac: plaintextHmacHex }),
                    credentials: 'same-origin'
                });

                const viewData = await viewed.json();
                if (viewed.status !== 200) {
                    this.sniptoDisplayFooter = this.t('WARNING: The automatic deletion of this snipto failed! This snipto will remain visible until it expires (1 week after creation).');
                    this.footerColorClass = 'text-red-600 dark:text-red-400';
                } else if (viewData.views_remaining !== 0) {
                    this.sniptoDisplayFooter = this.t('ATTENTION: This snipto was configured to be viewed more than 1 time. It can still be viewed :count more times.', { ':count': viewData.views_remaining });
                    this.footerColorClass = 'text-orange-600 dark:text-orange-400';
                }

                this.clearSensitiveRetrieval();
            } catch (err) {
                this.errorMessage = err.message;
            } finally {
                this.loading = false;
            }
        },

        renderPayload(decrypted) {
            const tryRender = () => {
                const container = document.querySelector('#snipto-payload-container');
                if (container) {
                    // Clear any existing content using replaceChildren
                    container.replaceChildren();

                    // Escape the text to prevent HTML interpretation
                    const escapedText = this.escapeHtml(decrypted);

                    // Get nonce from meta tag
                    const nonce = document.querySelector('meta[name="csp-nonce"]')?.content ||
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('nonce') ||
                        '';

                    // Detect theme for text color
                    const isDark = document.documentElement.classList.contains('dark');
                    const textColor = isDark ? '#f3f4f6' : '#111827'; // text-gray-100 or text-gray-900

                    // Create temp element to measure height (minimal styles)
                    const tempPre = document.createElement('pre');
                    tempPre.style.position = 'absolute';
                    tempPre.style.visibility = 'hidden';
                    tempPre.style.padding = '0';
                    tempPre.style.margin = '0';
                    tempPre.style.fontSize = '16px'; // text-base
                    tempPre.style.whiteSpace = 'pre-wrap';
                    tempPre.style.overflowWrap = 'anywhere';
                    tempPre.style.wordBreak = 'break-word';
                    tempPre.style.color = textColor; // Match iframe text color
                    tempPre.textContent = decrypted;
                    document.body.appendChild(tempPre);
                    const height = tempPre.offsetHeight;
                    document.body.removeChild(tempPre);

                    // Build srcdoc with minimal styles
                    const srcdoc = `
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <style nonce="${nonce}">
                                body {
                                    margin: 0;
                                    padding: 0;
                                    font-family: inherit;
                                    color: ${textColor}; /* Explicitly set for theme */
                                    background-color: transparent;
                                }
                                pre {
                                    margin: 0;
                                    padding: 0;
                                    font-size: 16px; /* text-base */
                                    white-space: pre-wrap;
                                    overflow-wrap: anywhere;
                                    word-break: break-word;
                                }
                            </style>
                        </head>
                        <body>
                            <pre>${escapedText}</pre>
                        </body>
                        </html>
                    `;

                    // Create sandboxed iframe
                    const iframe = document.createElement('iframe');
                    iframe.sandbox = ''; // Strict sandbox
                    // Use Trusted Types for srcdoc if available
                    iframe.srcdoc = this.trustedTypesPolicy
                        ? this.trustedTypesPolicy.createHTML(srcdoc)
                        : srcdoc;
                    iframe.style.width = '100%';
                    iframe.style.height = `${height}px`; // Auto-fit
                    iframe.style.border = 'none';
                    iframe.style.backgroundColor = 'transparent';

                    // Append to container
                    container.appendChild(iframe);

                    this.clearSensitiveRetrieval();
                } else {
                    let attempts = 0;
                    const maxAttempts = 10;
                    const pollInterval = setInterval(() => {
                        const element = document.querySelector('#snipto-payload-container');
                        if (element) {
                            tryRender();
                            clearInterval(pollInterval);
                        } else if (attempts >= maxAttempts) {
                            this.errorMessage = this.t('Failed to find display element.');
                            console.error('Failed to find #snipto-payload-container after retries');
                            clearInterval(pollInterval);
                        }
                        attempts++;
                    }, 100);
                }
            };

            // Wait for DOM to be ready
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                tryRender();
            } else {
                document.addEventListener('DOMContentLoaded', tryRender);
            }
        },

        escapeHtml(text) {
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        clearSensitiveRetrieval() {
            this.key = null;
            this.hmacKey = null;
            this.plaintextHmacKey = null;
            this.nonce = null;
            this.payload = null;
        },

        async submitSnipto() {
            if (!this.userInput.trim()) return;
            this.loading = true;

            const shortSecret = this.generateShortSecret(16);
            this.nonce = await this.generateRandomBytes(12);
            const { encKey, hmacKey, plaintextHmacKey } = await this.deriveKeys(shortSecret, this.nonce);
            this.key = encKey;
            this.hmacKey = hmacKey;
            this.plaintextHmacKey = plaintextHmacKey;

            const encrypted = await this.encryptPayload(this.userInput, this.key, this.nonce, this.hmacKey);

            const secretHash = await this.sha256Hex(new TextEncoder().encode(shortSecret));
            const plaintextHmac = await crypto.subtle.sign(
                { name: 'HMAC' },
                this.plaintextHmacKey,
                new TextEncoder().encode(this.userInput)
            );
            const plaintextHmacHex = Array.from(new Uint8Array(plaintextHmac))
                .map(b => b.toString(16).padStart(2, '0'))
                .join('');

            try {
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
                        nonce: this.nonce,
                        key_hash: secretHash,
                        plaintext_hmac: plaintextHmacHex
                    }),
                    credentials: 'same-origin'
                });

                if (res.status === 429) {
                    this.errorMessage = this.t('Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.');
                    return;
                }

                const body = await res.json();
                if (!res.ok || !body.success) {
                    this.errorMessage = this.t('An error occurred. Please try again.');
                    return;
                }

                this.slug = body.slug;
                this.showForm = false;
                this.showSuccess = true;
                this.fullUrl = `${window.location.origin}/${this.slug}#k=${shortSecret}`;
                QRCode.toCanvas(this.$refs.qrcode, this.fullUrl, { width: 128 });
                this.$refs.fullUrlInput.select();

                this.clearSensitiveCreation();
            } catch {
                this.errorMessage = this.t('An error occurred. Please try again.');
            } finally {
                this.loading = false;
            }
        },

        clearSensitiveCreation() {
            this.userInput = null;
            this.key = null;
            this.hmacKey = null;
            this.plaintextHmacKey = null;
            this.nonce = null;
        },

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

        async deriveKeys(secret, nonceHex) {
            const enc = new TextEncoder();
            const keyMaterial = await crypto.subtle.importKey(
                'raw',
                enc.encode(secret),
                { name: 'PBKDF2' },
                false,
                ['deriveKey']
            );

            const baseParams = {
                name: 'PBKDF2',
                salt: enc.encode(nonceHex),
                iterations: 100000,
                hash: 'SHA-256'
            };

            const encKey = await crypto.subtle.deriveKey(
                baseParams,
                keyMaterial,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt']
            );

            const hmacKey = await crypto.subtle.deriveKey(
                baseParams,
                keyMaterial,
                { name: 'HMAC', hash: 'SHA-256', length: 256 },
                false,
                ['sign', 'verify']
            );

            const plaintextHmacKey = await crypto.subtle.deriveKey(
                { ...baseParams, salt: enc.encode(nonceHex + 'plaintext') },
                keyMaterial,
                { name: 'HMAC', hash: 'SHA-256', length: 256 },
                false,
                ['sign', 'verify']
            );

            return { encKey, hmacKey, plaintextHmacKey };
        },

        async encryptPayload(plainText, encKey, nonceHex, hmacKey) {
            const enc = new TextEncoder();
            const nonce = this.hexToBytes(nonceHex);
            const encrypted = await crypto.subtle.encrypt(
                { name: 'AES-GCM', iv: nonce, tagLength: 128 },
                encKey,
                enc.encode(plainText)
            );

            const encryptedBytes = new Uint8Array(encrypted);
            const ciphertext = encryptedBytes.slice(0, -16);
            const authTag = encryptedBytes.slice(-16);

            let payload = new Uint8Array(ciphertext.length + authTag.length);
            payload.set(ciphertext);
            payload.set(authTag, ciphertext.length);

            if (hmacKey) {
                const hmacData = new Uint8Array([...ciphertext, ...authTag, ...enc.encode(nonceHex)]);
                const hmac = await crypto.subtle.sign({ name: 'HMAC' }, hmacKey, hmacData);
                const hmacBytes = new Uint8Array(hmac);
                const fullPayload = new Uint8Array(payload.length + hmacBytes.length);
                fullPayload.set(payload);
                fullPayload.set(hmacBytes, payload.length);
                payload = fullPayload;
            }

            return this.bytesToBase64(payload);
        },

        async decryptPayload(base64Payload, encKey, nonceHex, hmacKey) {
            const payloadBytes = this.base64ToBytes(base64Payload);
            const nonce = this.hexToBytes(nonceHex);
            const hmacLength = hmacKey ? 32 : 0;
            const authTagLength = 16;

            const ciphertextLength = payloadBytes.length - authTagLength - hmacLength;
            const ciphertext = payloadBytes.slice(0, ciphertextLength);
            const authTag = payloadBytes.slice(ciphertextLength, ciphertextLength + authTagLength);

            if (hmacKey) {
                const hmacReceived = payloadBytes.slice(-hmacLength);
                const hmacData = new Uint8Array([...ciphertext, ...authTag, ...new TextEncoder().encode(nonceHex)]);
                const hmacComputed = await crypto.subtle.sign({ name: 'HMAC' }, hmacKey, hmacData);
                if (!this.timingSafeEqual(new Uint8Array(hmacComputed), hmacReceived)) {
                    throw new Error('HMAC verification failed');
                }
            }

            const encrypted = new Uint8Array(ciphertext.length + authTag.length);
            encrypted.set(ciphertext);
            encrypted.set(authTag, ciphertext.length);

            const plainBuffer = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: nonce, tagLength: 128 },
                encKey,
                encrypted
            );
            return new TextDecoder().decode(plainBuffer);
        },

        async sha256Hex(bytes) {
            const hashBuffer = await crypto.subtle.digest('SHA-256', bytes);
            return Array.from(new Uint8Array(hashBuffer))
                .map(b => b.toString(16).padStart(2, '0'))
                .join('');
        },

        async generateRandomBytes(length) {
            const array = new Uint8Array(length);
            window.crypto.getRandomValues(array);
            return Array.from(array).map(b => ('00' + b.toString(16)).slice(-2)).join('');
        },

        hexToBytes(hex) {
            const bytes = new Uint8Array(hex.length / 2);
            for (let i = 0; i < bytes.length; i++) {
                bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
            }
            return bytes;
        },

        base64ToBytes(base64) {
            const binary = atob(base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return bytes;
        },

        bytesToBase64(bytes) {
            let binary = '';
            for (let i = 0; i < bytes.length; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary);
        },

        timingSafeEqual(a, b) {
            if (a.length !== b.length) return false;
            let result = 0;
            for (let i = 0; i < a.length; i++) {
                result |= a[i] ^ b[i];
            }
            return result === 0;
        },

        copyUrl() {
            navigator.clipboard.writeText(this.fullUrl).then(() => {
                this.showToast = true;
                setTimeout(() => this.showToast = false, 2000);
            });
        },

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        t(englishKey, replacements = []) {
            if (typeof window === 'undefined') return englishKey;
            if (!window.i18n) return englishKey;
            let str = window.i18n[englishKey] ?? englishKey;
            for (const [key, value] of Object.entries(replacements)) {
                str = str.replace(new RegExp(key, 'g'), value);
            }
            return str;
        }
    };
}

window.sniptoComponent = sniptoComponent;
