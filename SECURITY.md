# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.8.0   | :white_check_mark: |
| < 1.8.0 | :x:                |

## Reporting a Vulnerability

We take the security of Snipto seriously. If you discover a security vulnerability, please report it responsibly.

### How to Report

1. **Do not open a public issue.**
2. Email us at: **[contact@snipto.net](mailto:contact@snipto.net)**
3. Include a description of the vulnerability, steps to reproduce, and any potential impact.
4. We will respond within **72 hours** with an initial assessment.
5. We will work with you to resolve the issue and coordinate a public disclosure.

### What to Expect

*   **Acknowledgment:** You will receive a confirmation of your report.
*   **Updates:** We will provide regular updates on the status of the vulnerability.
*   **Disclosure:** We will coordinate with you on the timing of the public disclosure.

## Snipto's Security Architecture

Snipto is designed with a **zero-knowledge, end-to-end encrypted** model by default. Understanding this architecture is crucial for evaluating the impact of any potential vulnerability.

### Zero-Knowledge Principle

*   **Server Never Seys Keys:** For Secret, Password, and Snipto ID modes, the server never receives the user's passphrase, password, or private keys.
*   **Encryption is Client-Side:** All cryptographic operations (Argon2id key derivation, AES-256-GCM encryption, X25519 ECDH) are performed in the user's browser using WebAssembly (libsodium) or the WebCrypto API.
*   **Payloads are Ciphertext:** The server only stores encrypted payloads. Without the client-side key, the data is mathematically inaccessible.

### Cryptographic Standards

*   **Key Derivation:** Argon2id (memory-hard, OWASP strong tier) for all passphrase/password-based modes.
*   **Encryption:** AES-256-GCM for symmetric encryption (Secret, Password modes).
*   **Asymmetric Encryption:** X25519 ECDH for Snipto ID mode.
*   **Integrity:** HMAC-SHA256 for all encrypted payloads.

### Content Security Policy (CSP)

Snipto enforces a strict CSP to prevent XSS and other client-side attacks:
*   **No `unsafe-inline` or `unsafe-eval`:** Inline scripts/styles are prohibited.
*   **Trusted Types:** All DOM content injection uses Trusted Types (`snipto-srcdoc` policy) with sandboxed iframes.
*   **WASM Support:** `'wasm-unsafe-eval'` is the only exception in `script-src`, required for libsodium's WebAssembly.

### Snipto ID Security

*   **Non-Deterministic Generation:** The same passphrase generates a different Snipto ID each time (random 16-byte salt).
*   **Salt is Public:** The salt is published as part of the Snipto ID and stored on the server. Security relies on the secrecy of the passphrase.
*   **Private Keys Never Stored:** Private keys are derived on-demand from the passphrase + salt and are never persisted.

## Known Limitations

*   **Client-Side Trust:** Users must trust the Snipto client-side code. We encourage auditing the source code and verifying the Docker Hub image build process.
*   **Passphrase Strength:** Security is only as strong as the user's passphrase. We enforce minimum lengths (12 chars for Password mode, 20 chars for Snipto ID) and recommend strong, memorable passphrases.
*   **Browser Extensions:** As a browser-based E2EE solution, Snipto can be vulnerable to malicious browser extensions that intercept data before encryption or after decryption. We mitigate this by educating users on best practices in our [Safety Guide](/safety), including recommending the use of a dedicated, clean browser profile for sensitive snippets.
*   **URL Secret Mode Key Exposure:** In Random URL Secret mode, the encryption key is embedded in the URL fragment (`#key=...`), meaning anyone with the full URL can read the snippet. We mitigate this through the **ephemerality** of the snippet and a **1-hour expiration limit**. Furthermore, all Snipto modes are **automatically deleted from the server immediately upon retrieval**, limiting the window of opportunity for unauthorized access. For higher security, we recommend **Snipto ID mode**, which uses **asymmetric encryption** to eliminate the need to share a secret or password along with the URL.

## Contact

For any questions or concerns, please reach out via the email above.
