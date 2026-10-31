// Feature detection for libsodium (Argon2id + X25519 via WebAssembly).
// X25519 is performed via libsodium rather than WebCrypto because Firefox-based
// privacy browsers (Tor, Mullvad) block crypto.subtle.exportKey('jwk', ...) for
// X25519 under privacy.resistFingerprinting. Same algorithm, same outputs.
async function supportsLibsodium() {
    try {
        if (typeof WebAssembly === 'undefined' || typeof window.sodium === 'undefined') return false;
        await window.sodium.ready;
        return typeof window.sodium.crypto_pwhash === 'function'
            && typeof window.sodium.crypto_scalarmult === 'function'
            && typeof window.sodium.crypto_scalarmult_base === 'function';
    } catch {
        return false;
    }
}
window.supportsLibsodium = supportsLibsodium;

// ---------------------------------------------------------------------------
// X25519 public key validation (Snipto ID mode; audit finding F-2).
//
// sodium.crypto_scalarmult is raw RFC 7748: it accepts ANY 32 bytes as a peer
// public key. Curve25519 has cofactor 8, so a malicious counterparty — here
// the server, which supplies sender_public_key to the recipient — can hand
// over a small-order curve point or a quadratic-twist point instead of a real
// public key. The resulting shared secret then leaks the recipient's
// long-lived Snipto ID private key bit by bit (Lim-style invalid-curve
// recovery), and the key_hash = SHA-256(shared) we POST lets the server
// verify each key candidate offline. RFC 7748 §7 explicitly warns designers
// not to assume contributory behaviour on cofactored curves.
//
// One check closes all three classes (small-order curve points, twist points,
// and mixed torsion+subgroup points): the peer point must lie in the
// prime-order subgroup, i.e. [order]P = identity. Implemented as an
// unclamped BigInt port of the RFC 7748 §5 ladder with the group order as the
// scalar; a zero output means "in the subgroup" (the ladder encodes the
// identity as 0, and the only curve point with x = 0 is the order-2 point
// u = 0 — unique here because B²−4 is a quadratic residue mod p — which is
// rejected outright before the ladder runs).
// ---------------------------------------------------------------------------
const X25519_P = (1n << 255n) - 19n;
const X25519_A24 = 121665n; // (486662 − 2) / 4
const X25519_ORDER = (1n << 252n) + 27742317777372353535851937790883648493n;

function modPow(base, exp, mod) {
    let result = 1n;
    base = ((base % mod) + mod) % mod;
    while (exp > 0n) {
        if (exp & 1n) result = result * base % mod;
        base = base * base % mod;
        exp >>= 1n;
    }
    return result;
}

// RFC 7748 §5 Montgomery ladder, BigInt port, WITHOUT the ECDH scalar
// clamping — the scalar passed here is the group order, not a private key.
// Returns x([k]P) as a field element; the identity comes back as 0 because
// z_2^(p−2) of 0 is 0, exactly as the RFC procedure behaves.
function x25519Ladder(scalar, u) {
    const p = X25519_P;
    const x1 = u;
    let x2 = 1n, z2 = 0n, x3 = u, z3 = 1n, swap = 0n;

    for (let t = 254; t >= 0; t--) {
        const kt = (scalar >> BigInt(t)) & 1n;
        swap ^= kt;
        if (swap === 1n) {
            let tmp = x2; x2 = x3; x3 = tmp;
            tmp = z2; z2 = z3; z3 = tmp;
        }
        swap = kt;

        const A  = (x2 + z2) % p;
        const AA = A * A % p;
        const B  = ((x2 - z2) % p + p) % p;
        const BB = B * B % p;
        const E  = ((AA - BB) % p + p) % p;
        const C  = (x3 + z3) % p;
        const D  = ((x3 - z3) % p + p) % p;
        const DA = D * A % p;
        const CB = C * B % p;
        const sum  = (DA + CB) % p;
        const diff = ((DA - CB) % p + p) % p;
        x3 = sum * sum % p;
        z3 = x1 * diff % p * diff % p;
        x2 = AA * BB % p;
        z2 = E * ((AA + X25519_A24 * E) % p) % p;
    }

    // Single cswap AFTER the loop (Bernstein's original structure; RFC 7748
    // prints it dedented below the loop). The loop body swaps only at its top.
    if (swap === 1n) {
        let tmp = x2; x2 = x3; x3 = tmp;
        tmp = z2; z2 = z3; z3 = tmp;
    }

    return x2 * modPow(z2, p - 2n, p) % p;
}

// True iff the 32 little-endian bytes decode (per RFC 7748 rules: mask the
// sign bit, reduce non-canonical values mod p) to a point in the prime-order
// subgroup. False means: reject these bytes as a public key.
function x25519PeerKeyValid(pubBytes) {
    if (pubBytes.length !== 32) return false;

    let u = 0n;
    for (let i = 31; i >= 0; i--) u = (u << 8n) | BigInt(pubBytes[i]);
    u &= (1n << 255n) - 1n;           // RFC 7748: MUST mask the MSB
    if (u >= X25519_P) u %= X25519_P; // RFC 7748: MUST accept non-canonical, reduced

    if (u === 0n) return false; // order-2 point: shared secret is always zero

    return x25519Ladder(X25519_ORDER, u) === 0n;
}

export function sniptoComponent() {
    return {
        slug: null,
        // One-time reveal capability handed out by the metadata GET. The payload
        // is only ever released by POST /api/snipto/{slug}/reveal with this token
        // (and key_hash in the POST body for encrypted modes) — never via a URL
        // query string. Consumed on any reveal attempt, success or failure.
        revealToken: null,
        revealTokenConsumed: false,
        key: null,
        hmacKey: null,
        nonce: null,
        payload: null, // Uint8Array for base64 bytes
        views_remaining: 0,
        loading: true,
        showPayload: false,
        showForm: false,
        slugTooShort: false,
        showSuccess: false,
        errorMessage: '',
        userInput: '',
        // Create-form editor. Never affects the payload view.
        editorFocused: false,
        editorLine: 1,
        editorCol: 1,
        editorLineHeights: [],
        editorGutterFrame: null,
        fullUrl: '',
        showToast: false,
        toastMessage: '',
        contentHostId: null,
        calledInit: false,
        isThrottled: false,
        isLockedOut: false,
        throttleCountdown: 0,
        throttleInterval: null,
        lineWidths: null, // To store line widths for resize without sensitive data
        cleanupFunctions: [], // Array to store cleanup functions
        protectionType: 1, // Default to Random Secret (1)
        protectionPassword: '',
        passwordBlacklist: [],
        expirationValue: '1h',
        showPasswordPrompt: false,
        isPayloadEncrypted: true,
        showWeakPasswordModal: false,
        sniptoIdInput: '',
        sniptoIdPassphrase: '',
        cryptoSupported: null,
        senderPublicKey: null,
        keyProviderType: null,
        recipientSalt: null,
        sniptoIdPrefix: '',
        // First 8 chars of the recipient Snipto ID a mode-3 snipto was
        // encrypted for; shown on the success screen for out-of-band
        // verification (audit finding F-5).
        sentToIdFingerprint: '',
        showSniptoIdPrompt: false,
        passwordRevealed: false,
        passwordGenerated: false,
        passwordNonceHex: null,
        showLanguageWarningModal: false,
        pendingLanguageForm: null,
        decryptError: '', // '' | 'rusk_missing' | 'rusk_invalid' | 'integrity' | 'network'

        setDecryptError(variant) {
            this.decryptError = variant;
            this.showPasswordPrompt = false;
            this.showSniptoIdPrompt = false;
            this.showPayload = false;
            this.showForm = false;
            this.loading = false;
        },

        closeLanguageWarningModal() {
            this.showLanguageWarningModal = false;
            this.pendingLanguageForm = null;
        },

        submitPendingLanguageForm() {
            if (this.pendingLanguageForm) {
                this.pendingLanguageForm.submit();
            }
        },

        handleLanguageChangeRequest(event) {
            if (this.showPayload) {
                event.preventDefault();
                this.pendingLanguageForm = event.detail.form;
                this.showLanguageWarningModal = true;
            }
        },

        // Plaintext ("E2EE disabled") mode requires slug >= 8 chars server-side
        // (F-005: for unencrypted sniptos the slug IS the only secret). Encrypted
        // modes accept >= 4. The mode switcher disables mode 0 when this is true.
        get plaintextSlugTooShort() {
            return (this.slug || '').length < 8;
        },

        // "Create one for me": the slug is below every creation floor, so offer a
        // memorable 3-word diceware slug (hyphen-joined; ~31 bits on the English
        // EFF short list, ~37-38 bits on the larger locale lists) and reload the
        // page with it. Hyphens are legal in the slug route charset; the wordlist
        // loader already uses rejection sampling.
        async suggestSlug() {
            if ( ! window.generateDicewareSlug) return;
            let slug;
            do {
                slug = await window.generateDicewareSlug();
            } while (slug.length < 8); // defensive; word-joined slugs always clear the floor
            window.location.href = `/${slug}`;
        },

        async init() {
            this.slug = this.$el.dataset.slug || '';
            if (this.calledInit) return;
            this.calledInit = true;

            // Keep the line-number gutter in step with the document. Registered
            // once here; the textarea is the only writer in 'gutter' mode.
            this.$watch('userInput', () => this.scheduleGutterSync());

            // Initialize Trusted Types policy for srcdoc
            if (window.trustedTypes && trustedTypes.createPolicy) {
                this.trustedTypesPolicy = trustedTypes.createPolicy('snipto-srcdoc', {
                    createHTML: (input) => input // Input is already escaped via escapeHtml
                });
            }

            // The theme itself is already settled by the head script in
            // layouts/main.blade.php before this runs, and re-deriving it from
            // prefers-color-scheme here would silently undo a stored light
            // preference on a device set to dark. All that is left is keeping an
            // already-rendered snippet in step when the theme changes under it.
            const onThemeChange = (event) => this.applyPayloadTheme(event.detail);
            window.addEventListener('snipto:theme-change', onThemeChange);
            this.cleanupFunctions.push(() => window.removeEventListener('snipto:theme-change', onThemeChange));

            this.cryptoSupported = await supportsLibsodium();

            if (!this.slug) {
                this.showForm = true;
                this.protectionType = 1;
                this.loading = false;
                this.loadBlacklist();
                this.$nextTick(() => setTimeout(() => this.initEditor(), 100));
                return;
            }

            this.contentHostId = 'c-' + await this.generateRandomBytes(4);

            const shortSecretStr = new URLSearchParams(window.location.hash.substring(1)).get('k');
            let shortSecretBytes = null;
            if (shortSecretStr) {
                shortSecretBytes = new TextEncoder().encode(shortSecretStr);
            }

            // Initial check to see if slug exists and get protection type
            try {
                const res = await fetch(`/api/snipto/${this.slug}`, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' }
                });

                if (res.status === 404) {
                    // Slug hardening (F-005): creation requires >= 4 chars in every
                    // protection mode. Below that floor the create form could only
                    // ever 422, so don't load the editor at all — show an error.
                    // Checked after the probe so legacy short-slug sniptos created
                    // before the floor existed stay viewable.
                    if (this.slug.length < 4) {
                        this.slugTooShort = true;
                        return;
                    }
                    this.showForm = true;
                    this.protectionType = 1;
                    this.loadBlacklist();
                    this.$nextTick(() => setTimeout(() => this.initEditor(), 100));
                    return;
                } else if (res.status === 429) {
                    this.handleThrottling(res, true);
                    return;
                } else if (!res.ok) {
                    throw new Error();
                }

                const data = await res.json();
                this.protectionType = data.protection_type;
                this.revealToken = data.reveal_token || null;
                this.revealTokenConsumed = false;

                // Handle Protection Modes
                if (this.protectionType === 0) {
                    // Mode 0: Plaintext — the metadata GET never carries the payload;
                    // it is released only by POST reveal with the one-time token.
                    const res = await this.postReveal({ reveal_token: this.revealToken });
                    this.revealTokenConsumed = true;

                    if (res.status === 429) {
                        this.handleThrottling(res, true);
                        return;
                    }
                    if (!res.ok) throw new Error();

                    const revealed = await res.json();
                    this.showPayload = true;
                    this.isPayloadEncrypted = false;
                    const plainBytes = new TextEncoder().encode(revealed.payload);
                    this.$nextTick(() => this.renderPayload(plainBytes));
                } else if (this.protectionType === 1) {
                    // Mode 1: URL Secret — the probe response already carries the
                    // nonce and a still-valid reveal_token, so derive here and go
                    // straight to the reveal POST (one GET + one POST, like Mode 2).
                    if (!shortSecretBytes) {
                        this.setDecryptError('rusk_missing');
                        return;
                    }
                    let derived;
                    try {
                        derived = await this.deriveKeys(shortSecretBytes, data.nonce);
                    } catch {
                        this.setDecryptError('integrity');
                        return;
                    }
                    await this.retrieveAndDecrypt({
                        secretHash: derived.keyHash,
                        encKey: derived.encKey,
                        hmacKey: derived.hmacKey,
                        nonceHex: data.nonce,
                    });
                } else if (this.protectionType === 2) {
                    // Mode 2: Password — nonce is returned pre-auth so the client can
                    // reproduce the Argon2id+HKDF derivation that produces key_hash.
                    this.passwordNonceHex = data.nonce;
                    this.showPasswordPrompt = true;
                } else if (this.protectionType === 3) {
                    // Mode 3: Snipto ID (Asymmetric)
                    this.senderPublicKey = data.sender_public_key;
                    this.keyProviderType = data.key_provider_type;
                    this.recipientSalt   = data.recipient_salt;
                    // First 8 chars of the recipient's full Snipto ID = base64 of the salt's
                    // first 6 bytes (base64 encodes 3 bytes per 4 chars, so 6 bytes → 8 chars,
                    // no padding, and these chars depend only on salt — not pubkey).
                    try {
                        const saltBytes = this.base64ToBytes(this.recipientSalt);
                        this.sniptoIdPrefix = this.bytesToBase64(saltBytes.slice(0, 6)).replace(/=+$/, '');
                    } catch {
                        this.sniptoIdPrefix = '';
                    }
                    // Audit finding F-2 (defense in depth): /sniptoid saves
                    // generated IDs to localStorage. If the salt this snipto
                    // carries does not match the salt baked into the ID saved
                    // in this browser, the snipto was addressed to a different
                    // ID (or the server tampered with recipient_salt — the
                    // derived keypair would then fail key_hash anyway). Warn
                    // rather than let the user burn a passphrase attempt.
                    try {
                        const saved = JSON.parse(localStorage.getItem('sniptoid.savedIds') || '[]');
                        if (saved.length === 1 && this.recipientSalt
                            && saved[0].id.slice(0, 16) !== this.recipientSalt.slice(0, 16)) {
                            this.showToastMessage(this.t('Heads up: this Snipto was addressed to a different Snipto ID than the one saved in this browser. Make sure you were given the right one.'));
                        }
                    } catch { /* private mode / corrupt storage: skip the hint */ }
                    this.showSniptoIdPrompt = true;
                }
            } catch {
                this.setDecryptError('network');
            } finally {
                if (shortSecretBytes) shortSecretBytes.fill(0);
                this.loading = false;
            }
        },

        async loadBlacklist() {
            try {
                const res = await fetch('/blacklist.txt');
                if (!res.ok) return;
                const text = await res.text();
                this.passwordBlacklist = text.split('\n')
                    .map(p => p.trim().toLowerCase())
                    .filter(p => p.length >= 8);
            } catch (e) {
                console.warn('Failed to load password blacklist');
            }
        },

        // POST the reveal payload. Returns the raw Response so callers keep their
        // existing 403/429 handling. key_hash lives in this body — never in a URL.
        async postReveal(body) {
            return fetch(`/api/snipto/${encodeURIComponent(this.slug)}/reveal`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.getCsrfToken(),
                },
                body: JSON.stringify(body),
            });
        },

        // The reveal token is single-use and every metadata GET overwrites it, so
        // a retry (wrong password, wrong passphrase) must re-probe for a fresh one
        // before it can reveal. Returns false when the probe failed.
        async ensureRevealToken() {
            if (this.revealToken && !this.revealTokenConsumed) return true;

            const res = await fetch(`/api/snipto/${this.slug}`, {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) return false;

            const data = await res.json();
            this.revealToken = data.reveal_token || null;
            this.revealTokenConsumed = false;
            return !!this.revealToken;
        },

        // Both encrypted modes arrive here fully pre-derived from init()'s single
        // page-load probe: the nonce and the still-valid reveal_token came from it,
        // so retrieval is exactly one GET + one POST. (A prior failed attempt burned
        // the token, so ensureRevealToken() re-probes in that case.)
        async retrieveAndDecrypt(preDerived) {
            try {
                const secretHash = preDerived.secretHash;

                // A previous failed attempt consumed the token (the server burns it
                // on any attempt, right or wrong), so re-probe for a fresh one.
                if (!(await this.ensureRevealToken())) throw new Error();

                const res = await this.postReveal({
                    reveal_token: this.revealToken,
                    key_hash: secretHash,
                });
                this.revealTokenConsumed = true;

                if (res.status === 403) {
                    if (this.protectionType === 2) {
                        this.showToastMessage(this.t('Invalid password. Please try again.'));
                    } else {
                        this.setDecryptError('rusk_invalid');
                    }
                    return;
                }

                if (res.status === 429) {
                    this.handleThrottling(res, true);
                    return;
                }

                if (!res.ok) throw new Error();

                const data = await res.json();
                const nonceHex = preDerived.nonceHex;
                this.nonce = this.hexToBytes(nonceHex);
                const { encKey, hmacKey } = preDerived;

                const payloadBytes = this.base64ToBytes(data.payload);
                const decryptedBuffer = await this.decryptPayload(payloadBytes, encKey, this.nonce, hmacKey, nonceHex);
                const decryptedBytes = new Uint8Array(decryptedBuffer);

                this.showPayload = true;
                this.showPasswordPrompt = false;

                await this.$nextTick();
                this.renderPayload(decryptedBytes);
                decryptedBytes.fill(0);

                this.clearSensitiveRetrieval();
            } catch (err) {
                this.setDecryptError('integrity');
            }
        },

        async unlockWithPassword() {
            if (!this.protectionPassword.trim()) return;
            if (!this.passwordNonceHex) {
                this.setDecryptError('integrity');
                return;
            }
            this.loading = true;

            const pwdBytes = new TextEncoder().encode(this.protectionPassword);
            const { encKey, hmacKey, keyHash } = await this.deriveKeysFromPassword(pwdBytes, this.passwordNonceHex);
            pwdBytes.fill(0);
            this.protectionPassword = '';

            await this.retrieveAndDecrypt({
                secretHash: keyHash,
                encKey,
                hmacKey,
                nonceHex: this.passwordNonceHex,
            });

            this.loading = false;
        },

        closeWeakPasswordModal() {
            this.showWeakPasswordModal = false;
            this.$nextTick(() => {
                this.$refs.protectionPasswordField?.focus();
            });
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

                const textColor = this.payloadTextColor();

                // Build srcdoc.
                //
                // The colour is declared on the root only, never on body. Colour
                // is inherited, so body and the pre pick it up from html — which
                // is what lets one inline write on the iframe's root re-theme the
                // whole document in place (see applyPayloadTheme). Declaring it on
                // body as well would pin the value baked in at render time: an
                // inline style on the root outranks the stylesheet, but nothing
                // reaches body underneath it.
                //
                // Keep comments about this markup out of the CSS itself: it is a
                // JS template literal, so '//' is not a comment and a backtick
                // would close the string. Both silently wreck the generated sheet.
                const srcdoc = `
            <!DOCTYPE html>
            <html>
            <head>
                <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'nonce-${nonce}';">
                <style nonce="${nonce}">
                    html, body {
                        margin: 0;
                        padding: 0;
                        display: block;
                        width: 100%;
                        overflow: hidden;
                    }

                    html {
                        color: ${textColor};
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
                iframe.id = this.contentHostId;
                iframe.sandbox = 'allow-same-origin';
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

                // Measure the iframe's actual rendered content. The canvas estimate above is only
                // a placeholder to avoid a layout flash; the real height comes from the live DOM
                // inside the iframe, which accounts for the rendered font, container width, and
                // scrollbar gutter. Without this, content can be truncated until a window resize
                // forces a reflow.
                let resizeObserver = null;
                const adjustHeight = () => {
                    try {
                        const doc = iframe.contentDocument;
                        if (!doc || !doc.documentElement) return;
                        const measured = Math.max(
                            doc.documentElement.scrollHeight,
                            doc.body ? doc.body.scrollHeight : 0
                        );
                        if (measured > 0) {
                            iframe.style.height = `${measured}px`;
                        }
                    } catch {
                        // contentDocument inaccessible (sandbox edge case) — leave the canvas estimate.
                    }
                };

                iframe.addEventListener('load', () => {
                    // A theme change that fired while this document was still
                    // loading would have been written to the pre-load document and
                    // thrown away, leaving the colour baked into the srcdoc — which
                    // can then sit mismatched against the page background behind
                    // it. Re-apply once the real document is in place. When nothing
                    // changed mid-load this writes the value the srcdoc already has,
                    // so there is no flash.
                    this.applyPayloadTheme(this.currentTheme());
                    adjustHeight();
                    try {
                        const root = iframe.contentDocument && iframe.contentDocument.documentElement;
                        if (root && typeof ResizeObserver !== 'undefined') {
                            resizeObserver = new ResizeObserver(() => adjustHeight());
                            resizeObserver.observe(root);
                        }
                    } catch {
                        // No-op: resize handler below still covers viewport changes.
                    }
                });

                const resizeHandler = () => adjustHeight();
                window.addEventListener('resize', resizeHandler);

                // Store cleanup function
                this.cleanupFunctions.push(() => {
                    window.removeEventListener('resize', resizeHandler);
                    if (resizeObserver) resizeObserver.disconnect();
                });
            };

            // Wait for DOM to be ready
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                tryRender();
            } else {
                document.addEventListener('DOMContentLoaded', tryRender);
            }
        },

        // 'dark' or 'light', read back from the class the whole site keys off.
        // Only ever returns those two strings, so it doubles as the source of
        // truth when there is no event detail to trust.
        currentTheme() {
            return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        },

        // Text colour for the snippet inside the sandboxed iframe. That iframe is
        // a separate document, so it inherits nothing from the page's Tailwind
        // classes; it reads the same .dark class the rest of the site keys off.
        // Kept in one place because the value is baked into the srcdoc at render
        // time and also applied live on a theme change.
        payloadTextColor(theme) {
            return (theme || this.currentTheme()) === 'dark' ? '#f3f4f6' : '#111827';
        },

        // Re-theming while a snippet is on screen must not re-render it: the
        // payload has already been consumed and there is nothing to fetch again.
        // The iframe is same-origin (sandbox="allow-same-origin"), so patch its
        // document directly. Writing the root's inline colour is not blocked by
        // that document's own CSP — style-src only gates <style> elements and
        // style attributes that the parser sees, not CSSOM assignment — and it
        // cascades down to the <pre> because the srcdoc declares the colour on
        // <html> only.
        applyPayloadTheme(theme) {
            // 'snipto:theme-change' is a plain window event, so any script on the
            // page — an extension, or an XSS that already landed — can dispatch it
            // with any detail it likes. Accept only our own two values and ignore
            // everything else rather than coercing it, so a hostile detail can
            // never steer the write into the payload document.
            if (theme !== 'dark' && theme !== 'light') return;

            const iframe = document.getElementById(this.contentHostId);
            if (!iframe) return;
            let root = null;
            try {
                root = iframe.contentDocument && iframe.contentDocument.documentElement;
            } catch (e) {
                return;
            }
            if (root) root.style.color = this.payloadTextColor(theme);
        },

        // ---------------------------------------------------------------------
        // Create-form editor
        //
        // 'gutter' mode keeps a native <textarea> — so mobile keyboards, IME,
        // autocorrect and clipboard all stay native — and we draw the line
        // numbers ourselves. The textarea soft-wraps, which means one logical
        // line can occupy several visual lines; a fixed gutter row height would
        // therefore drift out of alignment. We measure each logical line's
        // wrapped height in an offscreen mirror and give the matching gutter row
        // that exact height, which keeps the numbers aligned without giving up
        // word wrap.
        // ---------------------------------------------------------------------
        initEditor() {
            const textarea = this.$refs.textarea;
            if (!textarea) return;

            textarea.focus();
            this.applyEditorHeight();
            this.observeEditorLayout();
            this.syncGutter();

            const gutter = this.$refs.gutter;
            const onScroll = () => {
                if (gutter) gutter.scrollTop = textarea.scrollTop;
                this.updateActiveLine();
            };
            textarea.addEventListener('scroll', onScroll, { passive: true });
            this.cleanupFunctions.push(() => textarea.removeEventListener('scroll', onScroll));

            // A resize changes the wrap width, so every logical line has to be
            // re-measured against the new content width.
            const onResize = () => {
                this.applyEditorHeight();
                this.scheduleGutterSync();
            };
            window.addEventListener('resize', onResize);
            this.cleanupFunctions.push(() => window.removeEventListener('resize', onResize));
        },

        // Coalesce bursts of edits (typing, pasting) into one measurement pass.
        scheduleGutterSync() {
            if (this.editorGutterFrame) cancelAnimationFrame(this.editorGutterFrame);
            this.editorGutterFrame = requestAnimationFrame(() => {
                this.editorGutterFrame = null;
                this.syncGutter();
            });
        },

        // The editor takes whatever vertical space the card has left after the
        // heading, the counter strip and the whole controls block beneath it.
        // Both sides of that subtraction need care: the "everything else" figure
        // is taken as card height minus editor height, which cancels the editor
        // out and so cannot feed its own height back in; and the space available
        // has to come from the viewport rather than from main's box, which grows
        // around a card that no longer fits. See applyEditorHeight().
        // Only a fallback: the real floor is the --editor-min-height custom
        // property, which the short-window media query lowers along with the
        // copy it drops there. Keeping it in CSS means one place decides both.
        // There is no maximum on purpose — the card is meant to fill the
        // viewport however tall it is, and the editor is what flexes.
        EDITOR_MIN_HEIGHT_FALLBACK: 200,

        applyEditorHeight() {
            const shell = this.$refs.editorShell;
            const card = this.$refs.card;
            const main = card ? card.closest('main') : null;
            if (!shell || !card || !main) return;

            const mainStyles = getComputedStyle(main);
            const padY = parseFloat(mainStyles.paddingTop || 0)
                + parseFloat(mainStyles.paddingBottom || 0);
            const mainBox = main.clientHeight - padY;
            // main is a flex-1 item in a min-h-screen column, so once the card
            // is taller than the viewport main grows to fit it and its own
            // clientHeight contains the card's height. Feeding that back in
            // makes the result self-referential and it settles wherever history
            // left it. The viewport is the real limit, so take the smaller box:
            // the two agree while the card fits, and the viewport one is right
            // once it doesn't.
            const available = Math.min(mainBox, window.innerHeight - padY);

            const chrome = card.getBoundingClientRect().height - shell.getBoundingClientRect().height;
            if (available <= 0 || chrome <= 0) return;

            const floor = parseFloat(getComputedStyle(shell).getPropertyValue('--editor-min-height'));
            shell.style.height = Math.max(
                Number.isFinite(floor) && floor > 0 ? floor : this.EDITOR_MIN_HEIGHT_FALLBACK,
                available - chrome
            ) + 'px';
        },

        // Switching protection mode changes how tall the controls block is —
        // Password and Snipto ID add inputs, expiry pickers and warnings — so
        // the leftover space has to be recomputed when that content resizes.
        observeEditorLayout() {
            const card = this.$refs.card;
            const shell = this.$refs.editorShell;
            if (!card || !shell || typeof ResizeObserver === 'undefined') return;

            // Trailing debounce. Switching protection mode animates the extra
            // inputs in, so the card keeps changing size for a few hundred ms;
            // measuring on the trailing edge gives the settled layout instead of
            // a frame mid-transition. It converges rather than looping: once the
            // height is right, writing it changes nothing and the observer stops
            // firing.
            let timer = null;
            const observer = new ResizeObserver(() => {
                if (timer) clearTimeout(timer);
                timer = setTimeout(() => {
                    timer = null;
                    this.applyEditorHeight();
                }, 120);
            });
            observer.observe(card);
            this.cleanupFunctions.push(() => {
                if (timer) clearTimeout(timer);
                observer.disconnect();
            });
        },

        // Above this many logical lines we stop measuring per line and fall back
        // to a uniform row height. Line numbers stay correct; only the alignment
        // of wrapped lines past the cap can drift, and only for pathological
        // pastes. Measuring tens of thousands of rows per keystroke is not free.
        GUTTER_MEASURE_LIMIT: 5000,

        syncGutter() {
            const gutter = this.$refs.gutter;
            const textarea = this.$refs.textarea;
            if (!gutter || !textarea) return;

            const lines = this.userInput.split('\n');

            // The form is hidden (zero-size box) — nothing measurable yet.
            if (textarea.clientWidth === 0) return;

            const styles = getComputedStyle(textarea);
            const contentWidth = textarea.clientWidth
                - parseFloat(styles.paddingLeft || 0)
                - parseFloat(styles.paddingRight || 0);
            if (contentWidth <= 0) return;

            const lineHeight = parseFloat(styles.lineHeight) || parseFloat(styles.fontSize) || 16;
            const measure = lines.length <= this.GUTTER_MEASURE_LIMIT;

            const mirror = this.ensureMirror(textarea, styles);
            mirror.style.width = contentWidth + 'px';

            // Build the mirror with one child per logical line, then read every
            // child's height in a single layout pass.
            const rows = gutter.children;
            while (gutter.children.length > lines.length) gutter.removeChild(gutter.lastChild);
            mirror.textContent = '';

            const mirrorRows = [];
            for (let i = 0; i < lines.length; i++) {
                const probe = document.createElement('div');
                probe.className = 'editor-mirror-row';
                // A non-breaking space gives an empty line a line box to measure;
                // a plain space would be collapsed away at the end of a run.
                probe.textContent = lines[i] === '' ? '\u00a0' : lines[i];
                mirror.appendChild(probe);
                mirrorRows.push(probe);
            }

            // Single reflow: heights are read after all rows exist.
            const heights = measure ? mirrorRows.map(row => row.offsetHeight) : null;

            // Cache derived geometry, never the text itself — the active-line
            // band needs the same numbers the gutter is sized from, and keeping
            // heights rather than lines is the same trade the existing
            // lineWidths field makes.
            this.editorLineHeights = heights
                ? heights
                : new Array(lines.length).fill(lineHeight);

            for (let i = 0; i < lines.length; i++) {
                let row = rows[i];
                if (!row) {
                    row = document.createElement('div');
                    row.className = 'editor-gutter-row';
                    gutter.appendChild(row);
                }
                if (row.textContent !== String(i + 1)) row.textContent = String(i + 1);
                row.style.height = this.editorLineHeights[i] + 'px';
            }

            mirror.textContent = '';
            this.updateActiveLine();
        },

        // Offscreen twin of the textarea. Font metrics are copied from the real
        // element so the measured wrap matches what the user actually sees.
        ensureMirror(textarea, styles) {
            let mirror = this.$refs.editorMirror;
            if (!mirror) {
                mirror = document.createElement('div');
                mirror.className = 'editor-mirror';
                mirror.setAttribute('aria-hidden', 'true');
                document.body.appendChild(mirror);
                this.$refs.editorMirror = mirror;
                this.cleanupFunctions.push(() => mirror.remove());
            }
            mirror.style.fontFamily = styles.fontFamily;
            mirror.style.fontSize = styles.fontSize;
            mirror.style.lineHeight = styles.lineHeight;
            mirror.style.letterSpacing = styles.letterSpacing;
            mirror.style.tabSize = styles.tabSize;
            return mirror;
        },

        // Position the band behind the caret's logical line. Its offset is the
        // sum of the heights of the lines above it — the very numbers the gutter
        // rows were sized from, so the band and the numbers cannot disagree, and
        // a line that soft-wraps gets a band as tall as all of its visual lines.
        updateActiveLine() {
            const band = this.$refs.activeLine;
            const textarea = this.$refs.textarea;
            if (!band || !textarea) return;

            const gutter = this.$refs.gutter;
            if (gutter) {
                const rows = gutter.children;
                for (let i = 0; i < rows.length; i++) {
                    rows[i].classList.toggle('is-active', i === this.editorLine - 1);
                }
            }

            const heights = this.editorLineHeights;
            if (!heights.length) {
                band.style.height = '0px';
                return;
            }

            const index = Math.max(0, Math.min(this.editorLine - 1, heights.length - 1));
            let top = 0;
            for (let i = 0; i < index; i++) top += heights[i] || 0;

            const padTop = parseFloat(getComputedStyle(textarea).paddingTop) || 0;
            band.style.top = (padTop + top - textarea.scrollTop) + 'px';
            band.style.height = (heights[index] || 0) + 'px';
        },

        // Tab would move focus out of the editor; insert an indent instead.
        insertIndent() {
            const textarea = this.$refs.textarea;
            if (!textarea) return;

            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const indent = '    ';
            textarea.value = textarea.value.slice(0, start) + indent + textarea.value.slice(end);
            textarea.selectionStart = textarea.selectionEnd = start + indent.length;
            this.userInput = textarea.value;
            this.updateEditorCaret();
        },

        updateEditorCaret() {
            const textarea = this.$refs.textarea;
            if (!textarea) return;
            const before = textarea.value.slice(0, textarea.selectionStart);
            const lastBreak = before.lastIndexOf('\n');
            this.editorLine = before.split('\n').length;
            // 1-based; with no newline before the caret lastBreak is -1, which
            // already yields column = length + 1.
            this.editorCol = before.length - lastBreak;
            this.updateActiveLine();
        },

        editorCaretLabel() {
            return this.t('Ln :line, Col :col', { ':line': this.editorLine, ':col': this.editorCol });
        },

        editorCountLabel() {
            const chars = this.userInput.length;
            const lines = this.userInput === '' ? 0 : this.userInput.split('\n').length;
            const locale = (typeof navigator !== 'undefined' && navigator.language) || 'en';
            return this.t(':count characters', { ':count': chars.toLocaleString(locale) })
                + ' · '
                + this.t(':count lines', { ':count': lines.toLocaleString(locale) });
        },

        // Drop the editor's plaintext buffer and its undo history. Clearing
        // this.userInput is not enough: an editor keeps its own copy of the
        // text, and undo history keeps earlier states of it. This mirrors the
        // fill(0) hygiene used for the encryption buffers elsewhere.
        clearEditor() {
            // Clear the Alpine mirror too, so "no plaintext left in either
            // place" holds whatever the call order is. The submit path already
            // blanks userInput itself; this keeps clearEditor() self-sufficient.
            this.userInput = '';
            const textarea = this.$refs.textarea;
            if (textarea) textarea.value = '';
            this.scheduleGutterSync();
        },

        // Cleanup method to be called when component is destroyed
        destroy() {
            if (this.editorGutterFrame) {
                cancelAnimationFrame(this.editorGutterFrame);
                this.editorGutterFrame = null;
            }
            this.cleanupFunctions.forEach(fn => fn());
            this.cleanupFunctions = [];
            if (this.throttleInterval) {
                clearInterval(this.throttleInterval);
            }
            this.clearSensitiveRetrieval();
        },

        clearSensitiveRetrieval() {
            this.key = null;
            this.hmacKey = null;
            this.revealToken = null;
            this.revealTokenConsumed = false;
            if (this.nonce) {
                this.nonce.fill(0);
                this.nonce = null;
            }
            if (this.payload) {
                this.payload.fill(0);
                this.payload = null;
            }
        },

        async submitSnipto(bypass = false) {
            if (!this.userInput.trim()) return;

            const finalProtectionType = parseInt(this.protectionType);

            // Validation: Password length
            if (finalProtectionType === 2) {
                if (this.protectionPassword.length < 12) {
                    this.showToastMessage(this.t('Password must be at least 12 characters long.'));
                    return;
                }

                // Check for weak password unless bypassed
                if (!bypass && this.analyzePasswordStrength(this.protectionPassword) < 3) {
                    this.showWeakPasswordModal = true;
                    return;
                }
            }

            this.loading = true;
            this.showWeakPasswordModal = false;

            let payloadToSend, nonceHex, secretHash, shortSecretStr;

            let senderPublicKey = null;
            let keyProviderType = null;
            let recipientSaltBase64 = null;

            // Encryption Logic based on Mode
            this.sentToIdFingerprint = '';
            if (finalProtectionType === 1 || finalProtectionType === 2) {
                // E2EE Mode (Secret or Password)
                let secretSourceStr;
                if (finalProtectionType === 1) {
                    shortSecretStr = this.generateShortSecret(21);
                    secretSourceStr = shortSecretStr;
                } else {
                    secretSourceStr = this.protectionPassword;
                    shortSecretStr = null;
                }

                const secretBytes = new TextEncoder().encode(secretSourceStr);
                nonceHex = await this.generateRandomBytes(12);
                this.nonce = this.hexToBytes(nonceHex);

                const derived = finalProtectionType === 2
                    ? await this.deriveKeysFromPassword(secretBytes, nonceHex)
                    : await this.deriveKeys(secretBytes, nonceHex);
                this.key = derived.encKey;
                this.hmacKey = derived.hmacKey;

                const userInputBytes = new TextEncoder().encode(this.userInput);
                payloadToSend = await this.encryptPayload(userInputBytes, this.key, this.nonce, this.hmacKey, nonceHex);
                secretHash = derived.keyHash;

                // Clean up sensitive bytes
                userInputBytes.fill(0);
                secretBytes.fill(0);
            } else if (finalProtectionType === 3) {
                // E2EE Mode (Snipto ID / Asymmetric)
                if (!this.sniptoIdInput.trim()) {
                    this.showToastMessage(this.t('Please enter a Snipto ID.'));
                    this.loading = false;
                    return;
                }

                if (!/^[A-Za-z0-9+/]{64}$/.test(this.sniptoIdInput.trim())) {
                    this.showToastMessage(this.t('Invalid Snipto ID format.'));
                    this.loading = false;
                    return;
                }

                // v3: Snipto ID = base64(salt(16) || pubkey(32))
                const idBytes = this.base64ToBytes(this.sniptoIdInput.trim());
                const recipientSaltBytes = idBytes.slice(0, 16);
                const recipientPubkeyBytes = idBytes.slice(16);

                // Audit finding F-2: a typed/corrupted ID whose pubkey half is a
                // small-order or twist point would produce a degenerate ECDH.
                // Reject with a clear message instead of encrypting to garbage.
                if (!x25519PeerKeyValid(recipientPubkeyBytes)) {
                    this.showToastMessage(this.t('Invalid Snipto ID format.'));
                    this.loading = false;
                    return;
                }

                // Audit finding F-5: show the recipient-ID fingerprint (same
                // 8-char format the /sniptoid page prints under your own ID)
                // on the success screen, so the sender can verify out-of-band
                // they encrypted to the ID the recipient actually published.
                this.sentToIdFingerprint = this.sniptoIdInput.trim().substring(0, 8);

                const recipientPubkeyBase64 = this.bytesToBase64(recipientPubkeyBytes); // 44 chars with '='
                recipientSaltBase64 = this.bytesToBase64(recipientSaltBytes); // 24 chars with '=='

                // Generate ephemeral X25519 key pair via libsodium (RFP-safe, see header comment)
                await sodium.ready;
                const ephPrivBytes = sodium.randombytes_buf(32);
                const ephPubBytes = sodium.crypto_scalarmult_base(ephPrivBytes);
                senderPublicKey = this.bytesToBase64(ephPubBytes);

                // Derive shared secret via ECDH(ephemeralPrivate, recipientPublic)
                const { encKey, hmacKey, sharedBytes } = await this.deriveKeysFromECDH(
                    ephPrivBytes, recipientPubkeyBase64, senderPublicKey
                );
                sodium.memzero(ephPrivBytes);

                nonceHex = await this.generateRandomBytes(12);
                this.nonce = this.hexToBytes(nonceHex);
                this.key = encKey;
                this.hmacKey = hmacKey;

                const userInputBytes = new TextEncoder().encode(this.userInput);
                payloadToSend = await this.encryptPayload(userInputBytes, this.key, this.nonce, this.hmacKey, nonceHex);

                // key_hash = SHA-256 of the ECDH shared secret (not the public key)
                secretHash = await this.sha256Hex(sharedBytes);
                sharedBytes.fill(0);

                keyProviderType = 'passphrase';
                shortSecretStr = null;

                userInputBytes.fill(0);
            } else {
                // Plaintext mode
                payloadToSend = this.userInput;
                nonceHex = null;
                secretHash = null;
                shortSecretStr = null;
            }

            this.userInput = '';
            // The Alpine property is only one copy of the plaintext. The editor
            // widget keeps its own buffer (and undo history), so clear that too.
            this.clearEditor();
            this.protectionPassword = '';
            this.sniptoIdInput = '';

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
                        protection_type: finalProtectionType,
                        expiration: this.expirationValue,
                        ...(finalProtectionType === 3 ? {
                            sender_public_key: senderPublicKey,
                            key_provider_type: keyProviderType,
                            recipient_salt:    recipientSaltBase64,
                        } : {}),
                    }),
                    credentials: 'same-origin'
                });

                if (res.status === 429) {
                    this.handleThrottling(res);
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
                if (finalProtectionType === 1) {
                    this.fullUrl += `#k=${shortSecretStr}`;
                }

                QRCode.toCanvas(this.$refs.qrcode, this.fullUrl, { width: 256, margin: 2 });
                this.$refs.fullUrlInput.select();

                this.clearSensitiveCreation();
            } catch {
                this.errorMessage = this.t('An error occurred. Please try again.');
            } finally {
                this.loading = false;
            }
        },

        // SYNC: deriveX25519KeyPair is also in sniptoid.js — the derivation block must match byte-for-byte.
        // `salt` is a 16-byte Uint8Array supplied by the caller (per-Snipto-ID random in v3).
        // libsodium-only: WebCrypto's exportKey('jwk') for X25519 is blocked under
        // privacy.resistFingerprinting in Tor/Mullvad (Firefox ESR). Same algorithm, same outputs.
        async deriveX25519KeyPair(passphrase, salt) {
            await sodium.ready;
            const privateKeyBytes = sodium.crypto_pwhash(
                32,
                passphrase,
                salt,
                3,
                64 * 1024 * 1024,
                sodium.crypto_pwhash_ALG_ARGON2ID13
            );

            const publicKeyBytes = sodium.crypto_scalarmult_base(privateKeyBytes);
            const publicKeyBase64 = this.bytesToBase64(publicKeyBytes);

            // Caller is responsible for sodium.memzero(privateKeyBytes) once ECDH is done.
            return { privateKeyBytes, publicKeyBase64 };
        },

        concatBytes(...arrays) {
            const total = arrays.reduce((sum, a) => sum + a.length, 0);
            const result = new Uint8Array(total);
            let offset = 0;
            for (const a of arrays) {
                result.set(a, offset);
                offset += a.length;
            }
            return result;
        },

        async deriveKeysFromECDH(privateKeyBytes, theirPublicKeyBase64, myPublicKeyBase64) {
            await sodium.ready;
            const theirPubBytes = this.base64ToBytes(theirPublicKeyBase64);

            // Audit finding F-2: reject peer keys outside the prime-order
            // subgroup BEFORE paying the ECDH. On the recipient path
            // (unlockWithSniptoId) theirPublicKeyBase64 is the SERVER-supplied
            // sender_public_key — a small-order or twist point there is a
            // Lim-style key-recovery attempt against the long-lived Snipto ID
            // private key, made worse by the key_hash we POST (offline oracle
            // over candidate keys). On the sender path this validates the ID
            // the user typed. See the x25519PeerKeyValid header comment.
            if (!x25519PeerKeyValid(theirPubBytes)) {
                throw new Error('X25519 peer public key rejected: not in the prime-order subgroup');
            }

            const sharedBytes = sodium.crypto_scalarmult(privateKeyBytes, theirPubBytes);

            // RFC 7748 §6.1 all-zero shared-secret check — belt-and-braces
            // after the subgroup check; never derive from a degenerate secret.
            let acc = 0;
            for (const b of sharedBytes) acc |= b;
            if (acc === 0) {
                throw new Error('X25519 produced an all-zero shared secret');
            }

            // Use HKDF (not PBKDF2) — ECDH output already has full entropy
            const hkdfKey = await crypto.subtle.importKey(
                'raw', sharedBytes, 'HKDF', false, ['deriveKey']
            );

            const enc = new TextEncoder();
            const hkdfSalt = enc.encode('snipto-enc-v1');

            // Identity-bind both public keys into HKDF info for key-substitution resistance
            const sorted = [myPublicKeyBase64, theirPublicKeyBase64].sort();
            const pubKeyContext = this.concatBytes(this.base64ToBytes(sorted[0]), this.base64ToBytes(sorted[1]));

            const encKey = await crypto.subtle.deriveKey(
                { name: 'HKDF', hash: 'SHA-256', salt: hkdfSalt, info: this.concatBytes(enc.encode('aes-key'), pubKeyContext) },
                hkdfKey,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt']
            );

            const hmacKey = await crypto.subtle.deriveKey(
                { name: 'HKDF', hash: 'SHA-256', salt: hkdfSalt, info: this.concatBytes(enc.encode('hmac-key'), pubKeyContext) },
                hkdfKey,
                { name: 'HMAC', hash: 'SHA-256', length: 256 },
                false,
                ['sign', 'verify']
            );

            return { encKey, hmacKey, sharedBytes };
        },

        async unlockWithSniptoId() {
            if (!this.sniptoIdPassphrase.trim()) return;

            if (this.sniptoIdPassphrase.length < 20) {
                this.showToastMessage(this.t('Passphrase must be at least 20 characters.'));
                return;
            }

            this.loading = true;

            if (!this.recipientSalt || !/^[A-Za-z0-9+/]{22}==$/.test(this.recipientSalt)) {
                this.setDecryptError('integrity');
                return;
            }

            try {
                const saltBytes = this.base64ToBytes(this.recipientSalt);
                const { privateKeyBytes, publicKeyBase64 } = await this.deriveX25519KeyPair(this.sniptoIdPassphrase, saltBytes);

                // Compute ECDH shared secret with sender's ephemeral public key (available from init())
                const { encKey, hmacKey, sharedBytes } = await this.deriveKeysFromECDH(
                    privateKeyBytes, this.senderPublicKey, publicKeyBase64
                );
                sodium.memzero(privateKeyBytes);

                // key_hash = SHA-256 of the ECDH shared secret (not the public key)
                const keyHash = await this.sha256Hex(sharedBytes);
                sharedBytes.fill(0);

                // key_hash goes in the POST body with a fresh one-time reveal token.
                if (!(await this.ensureRevealToken())) throw new Error();

                const res = await this.postReveal({
                    reveal_token: this.revealToken,
                    key_hash: keyHash,
                });
                this.revealTokenConsumed = true;

                if (res.status === 403) {
                    this.showToastMessage(this.t('Decryption failed. Please check your passphrase and try again.'));
                    return;
                }

                if (res.status === 429) {
                    this.handleThrottling(res, true);
                    return;
                }

                if (!res.ok) throw new Error();

                const data = await res.json();

                const nonce = this.hexToBytes(data.nonce);
                const payloadBytes = this.base64ToBytes(data.payload);
                const decryptedBuffer = await this.decryptPayload(payloadBytes, encKey, nonce, hmacKey, data.nonce);
                const decryptedBytes = new Uint8Array(decryptedBuffer);

                this.showPayload = true;
                this.showSniptoIdPrompt = false;

                await this.$nextTick();
                this.renderPayload(decryptedBytes);
                decryptedBytes.fill(0);

                this.clearSensitiveRetrieval();
            } catch (err) {
                this.sniptoIdPassphrase = '';
                this.setDecryptError('integrity');
                return;
            } finally {
                this.sniptoIdPassphrase = '';
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
            const limit = 256 - (256 % chars.length); // 248 — reject bytes >= limit to eliminate modulo bias
            let result = '';
            while (result.length < length) {
                const array = new Uint8Array(length * 2);
                window.crypto.getRandomValues(array);
                for (let i = 0; i < array.length && result.length < length; i++) {
                    if (array[i] < limit) {
                        result += chars[array[i] % chars.length];
                    }
                }
                array.fill(0);
            }
            return result;
        },

        async deriveKeys(secretBytes, nonceHex) {
            await sodium.ready;

            // Argon2id with OWASP strong tier — same params as Password mode
            // Argon2 requires exactly 16-byte salt; derive it from nonce via SHA-256 (domain-separated)
            const enc = new TextEncoder();
            const saltInput = new Uint8Array(enc.encode('snipto-urlsecret-v1').length + enc.encode(nonceHex).length);
            saltInput.set(enc.encode('snipto-urlsecret-v1'));
            saltInput.set(enc.encode(nonceHex), enc.encode('snipto-urlsecret-v1').length);
            const saltDigest = await crypto.subtle.digest('SHA-256', saltInput);
            const salt = new Uint8Array(saltDigest).slice(0, 16);

            const master = sodium.crypto_pwhash(
                32,
                secretBytes,
                salt,
                3,
                64 * 1024 * 1024,
                sodium.crypto_pwhash_ALG_ARGON2ID13
            );

            // HKDF to derive separate AES-GCM and HMAC keys from the 32-byte master
            const hkdfKey = await crypto.subtle.importKey(
                'raw', master, 'HKDF', false, ['deriveKey', 'deriveBits']
            );
            master.fill(0);  // zero out immediately

            const encKey = await crypto.subtle.deriveKey(
                { name: 'HKDF', hash: 'SHA-256', salt: new TextEncoder().encode(nonceHex), info: new TextEncoder().encode('aes-key') },
                hkdfKey,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt']
            );

            const hmacKey = await crypto.subtle.deriveKey(
                { name: 'HKDF', hash: 'SHA-256', salt: new TextEncoder().encode(nonceHex), info: new TextEncoder().encode('hmac-key') },
                hkdfKey,
                { name: 'HMAC', hash: 'SHA-256', length: 256 },
                false,
                ['sign', 'verify']
            );

            // Derive key_hash from Argon2id master via HKDF (mirrors Mode 2).
            // This requires the nonce, which is returned in the pre-auth GET response for Mode 1.
            const keyHashBits = await crypto.subtle.deriveBits(
                { name: 'HKDF', hash: 'SHA-256', salt: new TextEncoder().encode(nonceHex), info: new TextEncoder().encode('key-hash') },
                hkdfKey,
                256
            );
            const keyHash = Array.from(new Uint8Array(keyHashBits))
                .map(b => b.toString(16).padStart(2, '0')).join('');

            return { encKey, hmacKey, keyHash };
        },

        async deriveKeysFromPassword(passwordBytes, nonceHex) {
            await sodium.ready;

            const enc = new TextEncoder();
            const saltInput = new Uint8Array(enc.encode('snipto-password-v2').length + enc.encode(nonceHex).length);
            saltInput.set(enc.encode('snipto-password-v2'));
            saltInput.set(enc.encode(nonceHex), enc.encode('snipto-password-v2').length);
            const saltDigest = await crypto.subtle.digest('SHA-256', saltInput);
            const salt = new Uint8Array(saltDigest).slice(0, 16);

            const master = sodium.crypto_pwhash(
                32,
                passwordBytes,
                salt,
                3,
                64 * 1024 * 1024,
                sodium.crypto_pwhash_ALG_ARGON2ID13
            );

            const hkdfKey = await crypto.subtle.importKey(
                'raw', master, 'HKDF', false, ['deriveKey', 'deriveBits']
            );
            master.fill(0);

            const hkdfSalt = enc.encode(nonceHex);

            const encKey = await crypto.subtle.deriveKey(
                { name: 'HKDF', hash: 'SHA-256', salt: hkdfSalt, info: enc.encode('aes-key') },
                hkdfKey,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt']
            );

            const hmacKey = await crypto.subtle.deriveKey(
                { name: 'HKDF', hash: 'SHA-256', salt: hkdfSalt, info: enc.encode('hmac-key') },
                hkdfKey,
                { name: 'HMAC', hash: 'SHA-256', length: 256 },
                false,
                ['sign', 'verify']
            );

            // key_hash shares the Argon2id master via a third HKDF label so the server-side
            // authorization token is itself memory-hard — cracking it costs the same as
            // cracking the ciphertext, closing the unsalted-SHA-256 weak link.
            const keyHashBits = await crypto.subtle.deriveBits(
                { name: 'HKDF', hash: 'SHA-256', salt: hkdfSalt, info: enc.encode('key-hash') },
                hkdfKey,
                256
            );
            const keyHash = Array.from(new Uint8Array(keyHashBits))
                .map(b => b.toString(16).padStart(2, '0')).join('');

            return { encKey, hmacKey, keyHash };
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

        async generatePassword() {
            this.protectionPassword = await window.generateDicewarePassphrase();
            this.passwordGenerated = true;
            // Auto-reveal: the user can immediately see what was generated.
            this.passwordRevealed = true;
        },

        onPasswordInput() {
            if (this.passwordGenerated) {
                this.passwordGenerated = false;
            }
        },

        togglePasswordReveal() {
            this.passwordRevealed = !this.passwordRevealed;
        },

        copyPassword() {
            if (!this.protectionPassword) return;
            navigator.clipboard.writeText(this.protectionPassword)
                .then(() => {
                    this.showToastMessage(this.t('Copied to clipboard!'));
                })
                .catch(() => {
                    this.showToastMessage(this.t('Copying failed. Please copy manually.'));
                });
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
                this.showToastMessage(this.t('Copied to clipboard!'));
            });
        },

        copyToClipboard() {
            const iframe = document.getElementById(this.contentHostId);
            if (!iframe) {
                this.showToastMessage(this.t('Copying failed. Please copy manually.'));
                return;
            }

            let vaultText = '';
            try {
                const doc = iframe.contentDocument || iframe.contentWindow.document;
                const preElement = doc.querySelector('pre');
                if (!preElement) throw new Error();
                vaultText = preElement.textContent;
            } catch {
                this.showToastMessage(this.t('Copying failed. Please copy manually.'));
                return;
            }

            navigator.clipboard.writeText(vaultText)
                .then(() => {
                    this.showToastMessage(this.t('Copied to clipboard!'));
                })
                .catch(() => {
                    this.showToastMessage(this.t('Copying failed. Please copy manually.'));
                });
        },

        showToastMessage(msg) {
            this.toastMessage = msg;
            this.showToast = true;
            setTimeout(() => this.showToast = false, 3000);
        },

        handleThrottling(res, reloadOnExpiry = false) {
            if (res.headers.get('X-RateLimit-Lockout') === '1') {
                this.isLockedOut = true;
                this.isThrottled = false;
                this.loading = false;
                if (this.throttleInterval) {
                    clearInterval(this.throttleInterval);
                    this.throttleInterval = null;
                }
                return;
            }

            const resetTimestamp = parseInt(res.headers.get('X-RateLimit-Reset'));
            if (!resetTimestamp) return;

            const now = Math.floor(Date.now() / 1000);
            this.throttleCountdown = Math.max(0, resetTimestamp - now);

            if (this.throttleCountdown <= 0) return;

            this.isThrottled = true;
            this.loading = false;

            if (this.throttleInterval) clearInterval(this.throttleInterval);

            this.throttleInterval = setInterval(() => {
                this.throttleCountdown--;
                if (this.throttleCountdown <= 0) {
                    clearInterval(this.throttleInterval);
                    this.isThrottled = false;
                    this.throttleInterval = null;
                    if (reloadOnExpiry) window.location.reload();
                }
            }, 1000);
        },

        analyzePasswordStrength(pwd) {
            const lowerInput = pwd.toLowerCase();

            if (this.passwordBlacklist.includes(lowerInput)) return 0;

            const rootWord = lowerInput.replace(/[^a-z]/g, '');
            if (rootWord.length >= 4 && this.passwordBlacklist.includes(rootWord)) return 0;

            let score = 0;

            // Length tiers
            if (pwd.length >= 10) score++;
            if (pwd.length >= 14) score++;
            if (pwd.length >= 20) score++;
            if (pwd.length >= 28) score++;
            if (pwd.length >= 40) score++;

            // Multi-word structure: 3+ word-like tokens of length ≥3 separated by space, dash, underscore, or dot
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

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        // Split the Snipto-ID heading translation around the `:prefix` placeholder so the
        // prefix can be rendered in its own <strong> via x-text. We can't use x-html here
        // because CSP enforces require-trusted-types-for 'script'.
        sniptoIdHeadingBefore() {
            const tpl = this.t('This snippet was sent to a Snipto ID starting with :prefix.');
            const idx = tpl.indexOf(':prefix');
            return idx === -1 ? tpl : tpl.slice(0, idx);
        },

        sniptoIdHeadingAfter() {
            const tpl = this.t('This snippet was sent to a Snipto ID starting with :prefix.');
            const idx = tpl.indexOf(':prefix');
            return idx === -1 ? '' : tpl.slice(idx + ':prefix'.length);
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

if (window.Alpine) {
    window.Alpine.data('sniptoComponent', sniptoComponent);
} else {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('sniptoComponent', sniptoComponent);
    });
}
