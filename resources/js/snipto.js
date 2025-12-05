export function sniptoComponent() {
    return {
        slug: null,
        key: null,
        hmacKey: null,
        nonce: null,
        payload: null, // Uint8Array for base64 bytes
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
        lineWidths: null, // To store line widths for resize without sensitive data
        cleanupFunctions: [], // Array to store cleanup functions
        encryptSnipto: true,
        isPayloadEncrypted: true,

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

            const shortSecretStr = new URLSearchParams(window.location.hash.substring(1)).get('k');
            let shortSecretBytes = null;
            if (shortSecretStr) {
                shortSecretBytes = new TextEncoder().encode(shortSecretStr);
            }

            if (!shortSecretBytes) {
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

                        if (data.exists && data.is_encrypted === false) {
                            this.showPayload = true;
                            this.isPayloadEncrypted = false;

                            const plainBytes = new TextEncoder().encode(data.payload);

                            this.$nextTick(() => {
                                this.renderPayload(plainBytes);
                            });

                            if (data.views_remaining !== null && data.views_remaining > 0) {
                                this.sniptoDisplayFooter = this.t('ATTENTION: This snipto was configured to be viewed more than 1 time. It can still be viewed :count more times.', { ':count': data.views_remaining });
                                this.footerColorClass = 'text-orange-600 dark:text-orange-400';
                            }

                            this.loading = false;
                            return;
                        }

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
                const secretHash = await this.sha256Hex(shortSecretBytes);
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

                this.nonce = this.hexToBytes(data.nonce); // Uint8Array
                const nonceHex = data.nonce; // Keep hex for HMAC
                const { encKey, hmacKey } = await this.deriveKeys(shortSecretBytes, nonceHex);
                this.key = encKey;
                this.hmacKey = hmacKey;

                let decryptedBuffer;
                try {
                    this.payload = this.base64ToBytes(data.payload); // Uint8Array
                    decryptedBuffer = await this.decryptPayload(this.payload, this.key, this.nonce, this.hmacKey, nonceHex);
                } catch {
                    this.errorMessage = this.t('Could not decrypt the Snipto. Decryption failed or data tampered.');
                    return;
                }

                const decryptedBytes = new Uint8Array(decryptedBuffer);

                this.showPayload = true;

                this.$nextTick(() => {
                    this.renderPayload(decryptedBytes);
                    decryptedBytes.fill(0);  // Zero out AFTER rendering completes
                });

                if (data.views_remaining !== null && data.views_remaining > 0) {
                    this.sniptoDisplayFooter = this.t('ATTENTION: This snipto was configured to be viewed more than 1 time. It can still be viewed :count more times.', { ':count': data.views_remaining });
                    this.footerColorClass = 'text-orange-600 dark:text-orange-400';
                }

                this.clearSensitiveRetrieval();
            } catch (err) {
                this.errorMessage = err.message;
            } finally {
                if (shortSecretBytes) shortSecretBytes.fill(0);
                this.loading = false;
            }
        },

        renderPayload(decryptedBytes) {
            const tryRender = () => {
                const container = document.querySelector('#snipto-payload-container');
                if (!container) {
                    console.error('Container #snipto-payload-container not found');
                    return;
                }

                // Clear existing content
                container.replaceChildren();

                const decryptedText = new TextDecoder().decode(decryptedBytes).trim();
                const escapedText = this.escapeHtml(decryptedText);

                const nonce = document.querySelector('meta[name="csp-nonce"]')?.content ||
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('nonce') ||
                    '';

                const isDark = document.documentElement.classList.contains('dark');
                const textColor = isDark ? '#f3f4f6' : '#111827';

                // Build srcdoc
                const srcdoc = `
            <!DOCTYPE html>
            <html>
            <head>
                <style nonce="${nonce}">
                    html, body {
                        margin: 0;
                        padding: 0;
                        display: block;
                        width: 100%;
                        color: ${textColor};
                        overflow: hidden;
                    }

                    pre {
                        display: block;
                        margin: 0;
                        padding: 0;
                        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                        font-size: 16px;
                        white-space: pre-wrap;
                        overflow-wrap: anywhere;
                        word-break: break-word;
                        line-height: 1.5;
                    }
                </style>
            </head>
            <body>
                <pre>${escapedText}</pre>
            </body>
            </html>
        `;

                // Calculate height using canvas
                const containerStyles = getComputedStyle(container);
                const paddingLeft = parseFloat(containerStyles.paddingLeft) || 0;
                const paddingRight = parseFloat(containerStyles.paddingRight) || 0;
                let contentWidth = container.clientWidth - paddingLeft - paddingRight;

                if (contentWidth <= 0) {
                    console.warn('Container width is zero or negative. Using fallback width.');
                    contentWidth = window.innerWidth - 32; // Fallback to viewport width minus margins
                }

                const fontSize = 16;
                const lineHeight = fontSize * 1.5; // Fixed line-height

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                ctx.font = `${fontSize}px ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace`;
                const lines = decryptedText.split('\n');
                this.lineWidths = lines.map(line => ctx.measureText(line).width); // Store widths for resize (non-sensitive)
                let height = 0;
                for (const line of lines) {
                    const metrics = ctx.measureText(line);
                    let lineWidth = metrics.width;
                    lineWidth *= 1.02; // Small multiplier for rendering differences
                    const wrappedLines = Math.ceil(lineWidth / contentWidth) || 1;
                    height += wrappedLines * lineHeight;
                }
                canvas.remove();

                // Add buffer for margins/padding and rendering quirks
                height += lineHeight * 1;

                // Debug logging
                console.log('Content width:', contentWidth, 'Calculated height:', height);

                // Create sandboxed iframe with no permissions
                const iframe = document.createElement('iframe');
                iframe.sandbox = '';
                iframe.srcdoc = this.trustedTypesPolicy
                    ? this.trustedTypesPolicy.createHTML(srcdoc)
                    : srcdoc;
                iframe.style.width = '100%';
                iframe.style.height = `${height}px`;
                iframe.style.border = 'none';
                iframe.style.backgroundColor = 'transparent';
                iframe.style.overflow = 'hidden';
                iframe.style.display = 'block';

                // Append iframe
                container.appendChild(iframe);

                // Recalculate height on window resize
                const resizeHandler = () => {
                    let newContentWidth = container.clientWidth - paddingLeft - paddingRight;
                    if (newContentWidth <= 0) {
                        newContentWidth = window.innerWidth - 32;
                    }
                    let newHeight = 0;
                    for (const lineWidth of this.lineWidths) {
                        let adjustedWidth = lineWidth * 1.02;
                        const wrappedLines = Math.ceil(adjustedWidth / newContentWidth) || 1;
                        newHeight += wrappedLines * lineHeight;
                    }
                    newHeight += lineHeight * 1;
                    console.log('Resize - New content width:', newContentWidth, 'New height:', newHeight);
                    iframe.style.height = `${newHeight}px`;
                };
                window.addEventListener('resize', resizeHandler);

                // Store cleanup function
                this.cleanupFunctions.push(() => {
                    window.removeEventListener('resize', resizeHandler);
                });
            };

            // Wait for DOM to be ready
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                tryRender();
            } else {
                document.addEventListener('DOMContentLoaded', tryRender);
            }
        },

        // Cleanup method to be called when component is destroyed
        destroy() {
            this.cleanupFunctions.forEach(fn => fn());
            this.cleanupFunctions = [];
            this.clearSensitiveRetrieval();
        },

        clearSensitiveRetrieval() {
            this.key = null;
            this.hmacKey = null;
            if (this.nonce) {
                this.nonce.fill(0);
                this.nonce = null;
            }
            if (this.payload) {
                this.payload.fill(0);
                this.payload = null;
            }
        },

        async submitSnipto() {
            if (!this.userInput.trim()) return;
            this.loading = true;

            let payloadToSend, nonceHex, secretHash, shortSecretStr;
            let isEncrypted = false;

            // Only perform encryption if the checkbox is checked
            if (this.encryptSnipto) {
                isEncrypted = true;
                shortSecretStr = this.generateShortSecret(16);
                const shortSecretBytes = new TextEncoder().encode(shortSecretStr);

                nonceHex = await this.generateRandomBytes(12);
                this.nonce = this.hexToBytes(nonceHex);

                const { encKey, hmacKey } = await this.deriveKeys(shortSecretBytes, nonceHex);
                this.key = encKey;
                this.hmacKey = hmacKey;

                const userInputBytes = new TextEncoder().encode(this.userInput);

                payloadToSend = await this.encryptPayload(userInputBytes, this.key, this.nonce, this.hmacKey, nonceHex);
                secretHash = await this.sha256Hex(shortSecretBytes);

                // Clean up sensitive bytes
                userInputBytes.fill(0);
                shortSecretBytes.fill(0);
            } else {
                // Plaintext mode
                payloadToSend = this.userInput;
                nonceHex = null;
                secretHash = null;
                shortSecretStr = null;
            }

            this.userInput = ''; // Clear string early

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
                        payload: payloadToSend,
                        nonce: nonceHex,
                        key_hash: secretHash,
                        is_encrypted: isEncrypted
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

                this.fullUrl = `${window.location.origin}/${this.slug}`;
                if (isEncrypted) {
                    this.fullUrl += `#k=${shortSecretStr}`;
                }

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
            this.key = null;
            this.hmacKey = null;
            if (this.nonce) {
                this.nonce.fill(0);
                this.nonce = null;
            }
        },

        generateShortSecret(length) {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            let result = '';
            const array = new Uint8Array(length);
            window.crypto.getRandomValues(array);
            for (let i = 0; i < length; i++) {
                result += chars[array[i] % chars.length];
            }
            array.fill(0); // Clear random bytes
            return result;
        },

        async deriveKeys(secretBytes, nonceHex) {
            const keyMaterial = await crypto.subtle.importKey(
                'raw',
                secretBytes,
                { name: 'PBKDF2' },
                false,
                ['deriveKey']
            );

            const baseParams = {
                name: 'PBKDF2',
                salt: new TextEncoder().encode(nonceHex),
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

            return { encKey, hmacKey };
        },

        async encryptPayload(plainBytes, encKey, nonce, hmacKey, nonceHex) {
            const encrypted = await crypto.subtle.encrypt(
                { name: 'AES-GCM', iv: nonce, tagLength: 128 },
                encKey,
                plainBytes
            );

            const encryptedBytes = new Uint8Array(encrypted);
            const ciphertext = encryptedBytes.slice(0, -16);
            const authTag = encryptedBytes.slice(-16);

            let payload = new Uint8Array(ciphertext.length + authTag.length);
            payload.set(ciphertext);
            payload.set(authTag, ciphertext.length);

            if (hmacKey) {
                const enc = new TextEncoder();
                const hmacData = new Uint8Array(ciphertext.length + authTag.length + nonceHex.length);
                hmacData.set(ciphertext, 0);
                hmacData.set(authTag, ciphertext.length);
                hmacData.set(enc.encode(nonceHex), ciphertext.length + authTag.length);
                const hmac = await crypto.subtle.sign({ name: 'HMAC' }, hmacKey, hmacData);
                const hmacBytes = new Uint8Array(hmac);
                const fullPayload = new Uint8Array(payload.length + hmacBytes.length);
                fullPayload.set(payload);
                fullPayload.set(hmacBytes, payload.length);
                payload = fullPayload;
            }

            return this.bytesToBase64(payload);
        },

        async decryptPayload(payloadBytes, encKey, nonce, hmacKey, nonceHex) {
            const hmacLength = hmacKey ? 32 : 0;
            const authTagLength = 16;

            const ciphertextLength = payloadBytes.length - authTagLength - hmacLength;
            const ciphertext = payloadBytes.slice(0, ciphertextLength);
            const authTag = payloadBytes.slice(ciphertextLength, ciphertextLength + authTagLength);

            if (hmacKey) {
                const hmacReceived = payloadBytes.slice(-hmacLength);
                const enc = new TextEncoder();
                const hmacData = new Uint8Array(ciphertext.length + authTag.length + nonceHex.length);
                hmacData.set(ciphertext, 0);
                hmacData.set(authTag, ciphertext.length);
                hmacData.set(enc.encode(nonceHex), ciphertext.length + authTag.length);
                const hmacComputed = await crypto.subtle.sign({ name: 'HMAC' }, hmacKey, hmacData);
                if (!this.timingSafeEqual(new Uint8Array(hmacComputed), hmacReceived)) {
                    throw new Error('HMAC verification failed');
                }
            }

            const encrypted = new Uint8Array(ciphertext.length + authTag.length);
            encrypted.set(ciphertext);
            encrypted.set(authTag, ciphertext.length);

            return await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: nonce, tagLength: 128 },
                encKey,
                encrypted
            ); // Return ArrayBuffer
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
        },

        escapeHtml(text) {
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    };
}

window.sniptoComponent = sniptoComponent;
