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

Snipto is designed with **end-to-end encryption (E2EE)** as a first-class principle. Here’s how it works technically:

- **Encryption Algorithm:**  
  Snippets are encrypted using **AES-256 in CBC mode** via the browser’s [Web Crypto API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Crypto_API).

- **Key Derivation:**  
  A short, random alphanumeric “secret” is generated in the browser. This secret is never sent to the server. The browser derives a 256-bit AES key from it using **PBKDF2-HMAC-SHA256** with 100,000 iterations.
    - The **Initialization Vector (IV)** is also randomly generated per snippet.
    - The IV doubles as the salt in PBKDF2, ensuring the derived key is unique even if the same short secret is reused.

- **Payload Handling:**
    - Plaintext is encrypted with the randomly generated key and IV client-side into base64 encoded ciphertext.
    - Only the ciphertext and IV are sent to the server.

- **Decryption:**  
  To read a snippet, the recipient must have the full URL including `#k=<secret>`. The secret is extracted from the hash fragment, which is **never transmitted in HTTP requests** (browsers do not send fragments to servers). The browser re-derives the AES key and decrypts the ciphertext locally.

- **Integrity Verification:**  
  The client also computes a **SHA-256 hash of the ciphertext** when marking a snippet as “viewed.” This ensures the server only deletes snippets that were successfully decrypted, preventing accidental or malicious premature deletion.

- **Ephemerality:**  
  Snippets are deleted after the configured number of views or TTL. This ensures encrypted data does not persist indefinitely. Currently, the number of views is always 1.

In short: **the server cannot decrypt your data** — only someone with the full URL (including the secret fragment) can.

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
