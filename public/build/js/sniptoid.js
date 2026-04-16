// Feature detection for X25519 Web Crypto support
async function supportsX25519() {
    try {
        await crypto.subtle.generateKey({ name: 'X25519' }, true, ['deriveKey', 'deriveBits']);
        return true;
    } catch {
        return false;
    }
}

export function sniptoidComponent() {
    return {
        passphrase: '',
        sniptoId: null,
        x25519Supported: null,
        loading: false,
        showToast: false,
        toastMessage: '',

        async init() {
            this.x25519Supported = await supportsX25519();
        },

        async deriveSniptoid() {
            if (!this.passphrase.trim()) return;

            if (this.passphrase.length < 16) {
                this.showToastMessage(this.t('Passphrase must be at least 16 characters.'));
                return;
            }

            this.loading = true;

            try {
                const { publicKeyBase64 } = await this.deriveX25519KeyPair(this.passphrase);
                this.passphrase = '';
                this.sniptoId = publicKeyBase64;
            } catch {
                this.showToastMessage(this.t('Failed to generate Snipto ID. Please try again.'));
            } finally {
                this.loading = false;
            }
        },

        // SYNC: deriveX25519KeyPair is also in snipto.js — keep both in sync
        async deriveX25519KeyPair(passphrase) {
            const enc = new TextEncoder();
            const salt = enc.encode('snipto-identity-v1');
            const keyMaterial = await crypto.subtle.importKey(
                'raw', enc.encode(passphrase), 'PBKDF2', false, ['deriveBits']
            );
            const rawPrivateBytes = new Uint8Array(
                await crypto.subtle.deriveBits(
                    { name: 'PBKDF2', salt, iterations: 600000, hash: 'SHA-256' },
                    keyMaterial, 256
                )
            );

            // PKCS8 wrapper for X25519: fixed 16-byte ASN.1 header + 32-byte private key
            const pkcs8Header = new Uint8Array([
                0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06,
                0x03, 0x2b, 0x65, 0x6e, 0x04, 0x22, 0x04, 0x20
            ]);
            const pkcs8 = new Uint8Array(48);
            pkcs8.set(pkcs8Header);
            pkcs8.set(rawPrivateBytes, 16);

            const privateKey = await crypto.subtle.importKey(
                'pkcs8', pkcs8, { name: 'X25519' }, true, ['deriveBits']
            );

            // Export to JWK to obtain the public key
            const jwk = await crypto.subtle.exportKey('jwk', privateKey);
            const publicKeyBase64 = jwk.x.replace(/-/g, '+').replace(/_/g, '/') + '=';

            // Clean up sensitive material
            rawPrivateBytes.fill(0);
            pkcs8.fill(0);

            return { publicKeyBase64 };
        },

        copySniptoId() {
            if (!this.sniptoId) return;
            navigator.clipboard.writeText(this.sniptoId)
                .then(() => {
                    this.showToastMessage(this.t('Copied to clipboard!'));
                })
                .catch(() => {
                    this.showToastMessage(this.t('Copying failed. Please copy manually.'));
                });
        },

        passphraseStrength() {
            if (this.passphrase.length < 16) return 0;
            return this.analyzePasswordStrength(this.passphrase);
        },

        analyzePasswordStrength(pwd) {
            const lowerInput = pwd.toLowerCase();

            const rootWord = lowerInput.replace(/[^a-z]/g, '');
            if (rootWord.length >= 4 && /^(.)\1+$/.test(rootWord)) return 0;

            let score = 0;
            if (pwd.length >= 16) score++;
            if (pwd.length >= 20) score++;
            if (pwd.length >= 28) score++;

            if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
            if (/[0-9]/.test(pwd)) score++;
            if (/[^A-Za-z0-9]/.test(pwd)) score++;

            if (/[a-z]{4,}[0-9!@#$%^&*]{1,3}$/i.test(pwd)) score -= 2;
            if (/1234|abcd|qwerty/i.test(pwd)) score -= 2;
            if (/(\w)\1{2,}/.test(pwd)) score -= 1;

            return Math.max(0, score);
        },

        showToastMessage(msg) {
            this.toastMessage = msg;
            this.showToast = true;
            setTimeout(() => this.showToast = false, 3000);
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

window.sniptoidComponent = sniptoidComponent;
