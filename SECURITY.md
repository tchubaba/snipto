# Security Policy

## Supported Versions

| Version  | Supported          |
|----------| ------------------ |
| 1.15.0   | :white_check_mark: |
| < 1.15.0 | :x:                |

## Reporting a Vulnerability

**PLEASE DON'T DISCLOSE SECURITY-RELATED ISSUES PUBLICLY, [SEE BELOW](#reporting-a-vulnerability).**

If you discover a security vulnerability, please report it privately using one of the following channels:

1.  **GitHub Private Vulnerability Reporting (Preferred)** — go to the repository's **Security** tab and click **"Report a vulnerability"**. This creates a private advisory visible only to maintainers and provides a structured workflow for triage, fix coordination, and CVE assignment.
2.  **Email** — send the details to **[contact@snipto.net](mailto:contact@snipto.net)**.

We will respond within **72 hours** with an initial assessment and work with you to resolve the issue and coordinate a public disclosure.

### What to Expect

*   **Acknowledgment:** You will receive a confirmation of your report.
*   **Updates:** We will provide regular updates on the status of the vulnerability.
*   **Disclosure:** We will coordinate with you on the timing of the public disclosure.

## Snipto's Security Architecture

Snipto is designed with a **zero-knowledge, end-to-end encrypted** model by default. Understanding this architecture is crucial for evaluating the impact of any potential vulnerability.

### Zero-Knowledge Principle

*   **Server Never Sees Keys:** For Secret, Password, and Snipto ID modes, the server never receives the user's passphrase, password, or private keys.
*   **Encryption is Client-Side:** All cryptographic operations (Argon2id key derivation, AES-256-GCM encryption, X25519 ECDH) are performed in the user's browser using WebAssembly (libsodium) or the WebCrypto API.
*   **Payloads are Ciphertext:** In the encrypted modes, the server only stores encrypted payloads. Without the client-side key, the data is mathematically inaccessible. (The Plaintext mode is an explicit user opt-out of E2EE and stores the snippet as-is; the UI warns clearly when it is active.)

### Cryptographic Standards

*   **Key Derivation:** Argon2id (memory-hard, OWASP strong tier) for all passphrase/password-based modes.
*   **Encryption:** AES-256-GCM for symmetric encryption (Secret, Password modes).
*   **Asymmetric Encryption:** X25519 ECDH for Snipto ID mode.
*   **Integrity:** HMAC-SHA256 for all encrypted payloads.

### Content Security Policy (CSP)

Snipto enforces a strict CSP to prevent XSS and other client-side attacks:
*   **No `unsafe-inline` or `unsafe-eval`:** Inline scripts/styles are only allowed with a per-request cryptographic nonce (`script-src`/`style-src` each carry a fresh nonce; the application's own inline scripts use it).
*   **Trusted Types:** All DOM content injection uses Trusted Types (`snipto-srcdoc` policy) with sandboxed iframes.
*   **WASM Support:** `script-src` contains only `'self'`, a per-request nonce, and `'wasm-unsafe-eval'` — the narrow WASM-only keyword required by libsodium's WebAssembly, which does not enable JavaScript `eval()`.
*   **No framed content:** `frame-src 'none'` blocks injected external frames (the payload's own sandboxed `srcdoc` iframes are exempt from this directive).
*   **Violation reporting:** The policy declares `report-to`, resolved to the in-app collector at `/api/csp-report` via the `Reporting-Endpoints` header, so CSP violations are observable server-side.

### Snipto ID Security

*   **Non-Deterministic Generation:** The same passphrase generates a different Snipto ID each time (random 16-byte salt).
*   **Salt is Public:** The salt is published as part of the Snipto ID and stored on the server. Security relies on the secrecy of the passphrase.
*   **Private Keys Never Stored:** Private keys are derived on-demand from the passphrase + salt and are never persisted.
*   **Peer Key Validation:** Before any X25519 key agreement, the browser fully validates the peer's public key (subgroup membership check per RFC 9496: `[order]P = identity`, computed with a BigInt Montgomery ladder) — both when a sender uses a typed-in Snipto ID and when a recipient receives the sender's public key from the server. Small-order and quadratic-twist points, which a malicious server could use to force a predictable shared secret, are rejected before any authorization token leaves the browser. The implementation is pinned by RFC 7748's published test vectors and cross-checked against libsodium in CI.
*   **Out-of-Band Verification:** After encrypting to a Snipto ID, the sender sees the first 8 characters of the ID fingerprint on the success screen, so both parties can confirm out-of-band that the message was addressed to the intended ID.

## Known Limitations

*   **Client-Side Trust:** Users must trust the Snipto client-side code. We encourage auditing the source code and verifying the Docker Hub image build process.
*   **Passphrase Strength:** Security is only as strong as the user's passphrase. We enforce minimum lengths (12 chars for Password mode, 20 chars for Snipto ID) and recommend strong, memorable passphrases.
*   **Browser Extensions:** As a browser-based E2EE solution, Snipto can be vulnerable to malicious browser extensions that intercept data before encryption or after decryption. We mitigate this by educating users on best practices in our [Safety Guide](/safety), including recommending the use of a dedicated, clean browser profile for sensitive snippets.
*   **URL Secret Mode Key Exposure:** In Random URL Secret mode, the encryption key is embedded in the URL fragment (`#k=...`), meaning anyone with the full URL can read the snippet. We mitigate this through the **ephemerality** of the snippet and a **1-hour expiration limit**. Furthermore, all Snipto modes are **automatically deleted from the server immediately upon retrieval**, limiting the window of opportunity for unauthorized access. Snippets that are never retrieved are purged from the database by a scheduled job (every 15 minutes in the shipped Docker images), so at-rest data does not outlive its nominal expiry by more than one scheduler interval. For higher security, we recommend **Snipto ID mode**, which uses **asymmetric encryption** to eliminate the need to share a secret or password along with the URL.

## Contact

For any questions or concerns, please reach out via the email above.
