# Snipto

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**Snipto** is a secure, lightweight online snippet sharing service. It allows users to quickly create and share text snippets via unique URLs, with **end-to-end encryption**, ephemeral storage, and QR code integration. Snipto is designed for personal use, teams, and anyone who wants a private alternative to public paste services.

---

## Features

### Core Features
- **Instant Snippet Creation:** No “create” page — simply visit a URL and start typing.
- **End-to-End Encryption:** All content is encrypted client-side. Only the person with the link and encryption key can read the snippet.
- **Ephemeral Snippets:** By default, snippets are deleted after first view, or they expire after a configurable TTL (default: 1 week).
- **Customizable View Limit:** Specify how many times a snippet can be viewed before it is automatically deleted.
- **QR Code Generation:** Generate a QR code upon snippet creation URL for easy sharing.

---

## How It Works

1. **Access a Snipto URL:** Visit `https://snipto.net/{slug}`.
2. **Create or View:**
    - If the slug does not exist, you can create a snippet.
    - If it exists, it'll retrieve your snippet and decrypt it client-side with the supplied encryption key.
3. **Snippet Encryption:** The snippet is encrypted in the browser with a randomly generated encryption key. The key is appended to the URL as `#k=`. The server stores only the encrypted payload.
4. **Shareable URL & QR Code:** After submission, the full snippet URL (with included randomly generated encryption key) along with a QR code is displayed.
5. **Automatic Expiration:** Snippets single use only — once they are viewed, they are removed from the database. Otherwise they'll expire automatically in 1 week.
6. **View Verification:** Only a client that successfully decrypts a snippet can mark it as viewed, preventing premature deletion.

---

## Usage Instructions

### Creating a Snippet
1. Navigate to `https://snipto.net/{your-slug}`.
2. Enter your content in the text area.
3. The snippet is encrypted in the browser.
4. Click **Submit**. The page dynamically shows your full URL with `#k=` and a QR code.

### Viewing a Snippet
1. Navigate to the snippet URL, including the `#k=` key.
2. The snippet will decrypt and be displayed in the browser.
3. The snippet is removed permanently from the database.

---

## Security Notes
Snipto is designed with end-to-end encryption (E2EE) as a core principle, ensuring that sensitive data remains confidential and secure. All encryption and decryption operations occur client-side in the browser using the Web Crypto API, with the server handling only encrypted data and metadata. Below is a technical overview of how Snipto achieves secure, ephemeral snippet sharing.

### Encryption Algorithm
Snippets are encrypted using AES-256 in Galois/Counter Mode (AES-GCM) via the browser’s Web Crypto API. AES-GCM provides both confidentiality and integrity, ensuring that encrypted data cannot be tampered with without detection. A 12-byte nonce (initialization vector) is randomly generated for each snippet to ensure uniqueness of encryption.

### Key Derivation
A random, 16-character alphanumeric “short secret” is generated client-side using the browser’s cryptographically secure random number generator. This secret, passed in the URL fragment (e.g., `#k=<secret>`), is never sent to the server. The browser derives three 256-bit keys from the secret using PBKDF2-HMAC-SHA256 with 100,000 iterations:

- **Encryption Key**: Used for AES-GCM encryption and decryption of the snippet.
- **Ciphertext HMAC Key**: Used to compute an HMAC-SHA256 over the ciphertext, authentication tag, and nonce for additional integrity protection.
- **Plaintext HMAC Key**: Used to compute an HMAC-SHA256 over the plaintext, enabling secure view verification.

The nonce doubles as the salt for PBKDF2 (with a unique suffix for the plaintext HMAC key), ensuring that derived keys are unique even if the same short secret is reused.

### Payload Handling
- **Encryption**: The plaintext snippet is encrypted client-side using AES-GCM with the derived encryption key and nonce. An HMAC-SHA256 is computed over the ciphertext, authentication tag, and nonce using the ciphertext HMAC key. The combined payload (ciphertext + 16-byte authentication tag + 32-byte HMAC) is base64-encoded and sent to the server along with the nonce.
- **Server Storage**: The server stores only the encrypted payload, nonce, and metadata (e.g., expiration time, remaining views). It never receives the plaintext or short secret.
- **Access Control**: A SHA-256 hash of the short secret (`key_hash`) is sent to the server during snippet creation and stored. This hash is required to retrieve the encrypted payload, preventing unauthorized access.

### Decryption
To read a snippet, the recipient must access the full URL, including the fragment (e.g., `snipto.com/<slug>#k=<secret>`). The browser:

1. Extracts the short secret from the URL fragment, which is never sent to the server (per browser URL handling).
2. Computes the SHA-256 hash of the secret (`key_hash`) and sends it in the `GET /api/snipto/<slug>?key_hash=...` request to retrieve the encrypted payload.
3. Derives the encryption and HMAC keys using PBKDF2.
4. Verifies the ciphertext HMAC and decrypts the payload using AES-GCM, ensuring both confidentiality and integrity.

If the URL fragment is missing, the server returns only metadata (e.g., whether the slug exists, expiration time, remaining views), prompting the user to provide the secret. If the slug does not exist, the client displays a form to create a new snippet.

### Integrity and View Verification
- **Ciphertext Integrity**: AES-GCM’s authentication tag and the additional HMAC-SHA256 ensure that any tampering with the ciphertext or nonce is detected, preventing display of corrupted data.
- **View Verification**: After successful decryption, the client computes an HMAC-SHA256 of the plaintext using the plaintext HMAC key and sends it to the server via `POST /api/snipto/<slug>/viewed`. The server verifies this against the stored `plaintext_hmac` to confirm decryption, preventing unauthorized view consumption or premature deletion. This eliminates risks like dictionary attacks on low-entropy plaintexts, as the HMAC is keyed with the secret.

## Ephemerality
Snippets are ephemeral, automatically deleted after a configured number of views (default: 1) or a time-to-live (TTL, default: 1 week). The server decrements the view count only after verifying the plaintext HMAC, ensuring that only successful decryptions consume views. Expired or fully viewed snippets are inaccessible and removed from the server.

### Slug Existence Checking
When a user visits `snipto.com/<slug>` without a key, the client checks if the slug exists by sending a `GET /api/snipto/<slug>` request without a `key_hash`. The server returns minimal metadata (e.g., `exists: true`, expiration, views remaining) if the slug exists, prompting for the key, or a 404 if it does not, allowing creation of a new snippet. This preserves usability while protecting the encrypted payload behind the `key_hash`.

### Security Features
- **End-to-End Encryption**: The server never sees the plaintext or short secret, ensuring only users with the full URL can decrypt snippets.
- **Access Control**: The `key_hash` requirement prevents unauthorized access to encrypted payloads, reducing the risk of data exposure.
- **Tamper Resistance**: AES-GCM and HMAC-SHA256 ensure data integrity, detecting any modifications to the ciphertext or nonce.
- **Fishing Mitigation**: Server-side rate-limiting prevents brute-force slug enumeration. Keyless GET requests reveal only metadata, not the encrypted payload.
- **Secure Key Handling**: The short secret (16 chars, ~95 bits entropy) is high-entropy and processed only client-side. URL fragments avoid server transmission, though users are advised to share URLs securely to prevent leakage via browser history or extensions.

---

In summary, Snipto ensures that only users with the full URL (including the secret fragment) can access and decrypt snippets, with robust encryption, integrity checks, and access controls. The server handles only encrypted data and metadata, maintaining E2EE and ephemerality for secure, temporary sharing.


---

## Future Plans
- Support for **Markdown**, **images**, and other media types.
- Custom TTL and view-limit configuration per snippet.
- Optional client-side password management for extra security.

---

## License
This project is licensed under the [MIT License](LICENSE).

---

**Snipto** — Share your snippets. End-to-end encrypted.
