# Snipto

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**Snipto** is a secure, lightweight online snippet sharing service. It allows users to quickly create and share text snippets via unique URLs, with **end-to-end encryption**, ephemeral storage, and QR code integration. Snipto is designed for personal use, teams, and anyone who wants a private alternative to public paste services.

---

## Features

### Core Features
- **Instant Snippet Creation:** No “create” page — simply visit a URL and start typing.
- **End-to-End Encryption (Optional):** All content is encrypted client-side by default. Only the person with the link and encryption key can read the snippet. Plaintext sharing is also supported.
- **Ephemeral Snippets:** Snippets are deleted after the first view and expire automatically after 1 hour.
- **QR Code Generation:** Generate a QR code upon snippet creation for easy mobile sharing.
- **Security Focused:** Built with Laravel 12, Alpine.js (CSP-compliant build), and strict Content Security Policy (CSP) headers.

---

## How It Works

1. **Access a Snipto URL:** Visit `https://snipto.net/{slug}`.
2. **Create or View:**
    - If the slug does not exist, you can create a snippet.
    - If it exists, Snipto retrieves the payload. If encrypted, it decrypts it client-side with the key from the URL fragment.
3. **Snippet Sharing Modes:**
    - **Encrypted (Default):** The snippet is encrypted in the browser with a randomly generated 256-bit key. The key is appended to the URL as `#k=`. The server never receives this key; it only stores the encrypted payload and a hash of the key for verification.
    - **Plaintext (Optional):** Users can opt-out of encryption. In this mode, the snippet is sent to the server as-is. This is useful for non-sensitive data but lacks the privacy guarantees of E2EE. The UI provides a clear warning when this mode is active.
4. **Automatic Expiration:** Snippets are single-use only — once they are successfully viewed, they are removed from the database. All snippets expire automatically 1 hour after creation.
5. **Secure Rendering:** Decrypted content is rendered inside a sandboxed iframe using Trusted Types to prevent XSS.

---

## Usage Instructions

### Creating a Snippet
1. Navigate to `https://snipto.net/{your-slug}`.
2. Enter your content in the text area.
3. Ensure **"Use end-to-end encryption"** is toggled on (default) for maximum security.
4. Click **Snipto it**. The page shows your full URL (including the `#k=` fragment for encrypted snippets) and a QR code.

### Viewing a Snippet
1. Navigate to the snippet URL. If the snippet is encrypted, the URL must include the `#k=` fragment.
2. The snippet will decrypt (if necessary) and be displayed.
3. Once viewed, the snippet is immediately removed from the database.

---

## Technical Security Overview

Snipto is designed with end-to-end encryption (E2EE) as a core principle. All encryption and decryption operations occur client-side in the browser using the Web Crypto API.

### Encryption Algorithm
Snippets are encrypted using **AES-256-GCM**. A 12-byte random nonce is generated for each snippet.

### Key Derivation
A 16-character alphanumeric "short secret" is generated client-side. This secret is passed in the URL fragment and is never sent to the server. The browser derives encryption and HMAC keys using **PBKDF2-HMAC-SHA256** with 100,000 iterations.

### Payload Handling
- **Server Storage:** The server stores only the base64-encoded encrypted payload (ciphertext + auth tag + HMAC), the nonce, and a SHA-256 hash of the secret (`key_hash`).
- **Access Control:** The `key_hash` is required to retrieve the encrypted payload from the API, preventing unauthorized access even if the slug is guessed.
- **Integrity:** AES-GCM and an additional HMAC-SHA256 ensure that any tampering with the ciphertext or nonce is detected during decryption.

### CSP & Frontend Security
Snipto employs a multi-layered defense-in-depth strategy to protect user data and prevent common web attacks.

- **Strict Content Security Policy (CSP):** Using `Spatie\Csp`, the application enforces a strict policy:
    - `default-src 'none'`: All resources are blocked by default.
    - `frame-ancestors 'none'`: Prevents clickjacking by disallowing the app from being embedded in any iframe.
    - `connect-src 'self'`: Restricts AJAX requests only to the origin server.
    - `require-trusted-types-for 'script'`: Mandates the use of Trusted Types to prevent DOM-based XSS.
    - **Alpine.js CSP:** Uses the CSP-compatible build of Alpine.js to avoid `unsafe-eval`. All inline scripts and styles are protected with cryptographically secure nonces (`@cspNonce`).

- **Cross-Origin Isolation:** Specialized middleware ensures the browser runs in a secure, isolated context:
    - `Cross-Origin-Opener-Policy: same-origin` (COOP)
    - `Cross-Origin-Embedder-Policy: require-corp` (COEP)
    - `Cross-Origin-Resource-Policy: same-origin` (CORP)
    - `Referrer-Policy: strict-origin-when-cross-origin`

- **Secure Content Rendering:** 
    - Decrypted snippets are rendered in a **sandboxed `<iframe>`** (`sandbox=""`) with no permissions, preventing the snippet content from executing scripts or interacting with the main document.
    - Implements a **Trusted Types policy** (`snipto-srcdoc`) to sanitize and safely inject the `srcdoc` into the sandboxed iframe.

---

## License
This project is licensed under the [MIT License](LICENSE).

---

**Snipto** — Share your snippets. End-to-end encrypted.
