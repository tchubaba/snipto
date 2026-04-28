// Feature detection for X25519 Web Crypto support
async function supportsX25519() {
    try {
        await crypto.subtle.generateKey({ name: 'X25519' }, true, ['deriveKey', 'deriveBits']);
        return true;
    } catch {
        return false;
    }
}

// Feature detection for Argon2id (via libsodium.js / WebAssembly)
async function supportsArgon2id() {
    try {
        if (typeof WebAssembly === 'undefined' || typeof window.sodium === 'undefined') return false;
        await window.sodium.ready;
        return typeof window.sodium.crypto_pwhash === 'function';
    } catch {
        return false;
    }
}

export function sniptoidComponent() {
    return {
        passphrase: '',
        sniptoId: null,
        cryptoSupported: null,
        loading: false,
        showToast: false,
        toastMessage: '',
        passphraseRevealed: false,
        passphraseAcknowledged: false,
        passphraseGenerated: false,

        async init() {
            const [x25519Ok, argon2Ok] = await Promise.all([supportsX25519(), supportsArgon2id()]);
            this.cryptoSupported = x25519Ok && argon2Ok;
        },

        async deriveSniptoid() {
            if (!this.passphrase.trim()) return;

            if (this.passphrase.length < 16) {
                this.showToastMessage(this.t('Passphrase must be at least 16 characters.'));
                return;
            }

            if (this.passphraseGenerated && !this.passphraseAcknowledged) {
                this.showToastMessage(this.t('Reveal or copy your passphrase first.'));
                return;
            }

            this.loading = true;

            try {
                // v3: random per-recipient salt published as part of the Snipto ID
                const salt = crypto.getRandomValues(new Uint8Array(16));
                const { publicKeyBase64 } = await this.deriveX25519KeyPair(this.passphrase, salt);

                // Snipto ID = base64(salt(16) || pubkey(32)) → 64 chars, no padding
                const pubkeyBytes = this.base64ToBytes(publicKeyBase64);
                const idBytes = new Uint8Array(48);
                idBytes.set(salt, 0);
                idBytes.set(pubkeyBytes, 16);
                this.sniptoId = this.bytesToBase64(idBytes);

                this.passphrase = '';
                this.passphraseGenerated = false;
                this.passphraseAcknowledged = false;
                this.passphraseRevealed = false;
            } catch {
                this.showToastMessage(this.t('Failed to generate Snipto ID. Please try again.'));
            } finally {
                this.loading = false;
            }
        },

        generatePassphrase() {
            this.passphrase = window.generateDicewarePassphrase(6);
            this.passphraseGenerated = true;
            // Sticky reveal: if the user already chose to reveal, keep it revealed across regenerations
            // and treat the new passphrase as acknowledged since it is already on screen.
            this.passphraseAcknowledged = this.passphraseRevealed;
        },

        onPassphraseInput() {
            // Manual edits clear the "generated" gate — the user knows what they typed.
            if (this.passphraseGenerated) {
                this.passphraseGenerated = false;
                this.passphraseAcknowledged = false;
            }
        },

        togglePassphraseReveal() {
            this.passphraseRevealed = !this.passphraseRevealed;
            if (this.passphraseRevealed) {
                this.passphraseAcknowledged = true;
            }
        },

        copyPassphrase() {
            if (!this.passphrase) return;
            navigator.clipboard.writeText(this.passphrase)
                .then(() => {
                    this.passphraseAcknowledged = true;
                    this.showToastMessage(this.t('Copied to clipboard!'));
                })
                .catch(() => {
                    this.showToastMessage(this.t('Copying failed. Please copy manually.'));
                });
        },

        base64ToBytes(b64) {
            const bin = atob(b64);
            const out = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
            return out;
        },

        bytesToBase64(bytes) {
            let bin = '';
            for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
            return btoa(bin);
        },

        // SYNC: deriveX25519KeyPair is also in snipto.js — the derivation block must match byte-for-byte.
        // `salt` is a 16-byte Uint8Array supplied by the caller (per-Snipto-ID random in v3).
        async deriveX25519KeyPair(passphrase, salt) {
            await sodium.ready;
            const rawPrivateBytes = sodium.crypto_pwhash(
                32,
                passphrase,
                salt,
                3,
                64 * 1024 * 1024,
                sodium.crypto_pwhash_ALG_ARGON2ID13
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
            if (this.passphrase.length < 20) return 0;
            return this.analyzePasswordStrength(this.passphrase);
        },

        analyzePasswordStrength(pwd) {
            const lowerInput = pwd.toLowerCase();

            const rootWord = lowerInput.replace(/[^a-z]/g, '');
            if (rootWord.length >= 4 && /^(.)\1+$/.test(rootWord)) return 0;

            let score = 0;

            // Length tiers
            if (pwd.length >= 20) score++;
            if (pwd.length >= 24) score++;
            if (pwd.length >= 28) score++;
            if (pwd.length >= 40) score++;

            // Multi-word structure
            const tokens = pwd.split(/[\s\-_.]+/).filter(t => t.length >= 3);
            if (tokens.length >= 3) score += 2;
            if (tokens.length >= 5) score++;

            // Diversity
            if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
            if (/[0-9]/.test(pwd)) score++;
            if (/[^A-Za-z0-9]/.test(pwd)) score++;

            // Penalties
            if (/[a-z]{4,}[0-9!@#$%^&*]{1,3}$/i.test(pwd)) score -= 2;
            if (/1234|abcd|qwerty/i.test(pwd)) score -= 2;
            if (/(\w)\1{2,}/.test(pwd)) score -= 1;

            return Math.max(0, Math.min(5, score));
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
