# Snipto

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**Snipto** is a secure, lightweight online snippet sharing service. It allows users to quickly create and share text snippets via unique URLs, with **end-to-end encryption**, ephemeral storage, and QR code integration. Snipto is designed for personal use, teams, and anyone who wants a private alternative to public paste services.

---

## Features

### Core Features
- **Instant Snippet Creation:** No “create” page — simply visit a URL and start typing.
- **End-to-End Encryption (E2EE):** Content is encrypted client-side by default. The server never sees the raw snippet or the encryption key.
- **Optional Password Protection:** Secure your snippets with a personal password.
- **Customizable Expiration:** Choose how long your snippet stays available (up to 1 week) when using password protection.
- **Ephemeral Snippets:** Snippets are deleted after the first view and expire automatically after the chosen time (default 1 hour).
- **QR Code Generation:** Generate a QR code upon snippet creation for easy mobile sharing.
- **Security Focused:** Built with Laravel 12, Alpine.js (CSP-compliant build), and strict Content Security Policy (CSP) headers.

---

## How It Works

1. **Access a Snipto URL:** Visit `https://snipto.net/{slug}`.
2. **Create or View:**
    - If the slug does not exist, you can create a new snippet.
    - If it exists, Snipto identifies the protection mode. If password-protected, the user is prompted for the password before the payload is retrieved.
3. **Snippet Sharing Modes:**
    - **Random Secret (Default):** The snippet is encrypted in the browser with a randomly generated 256-bit key. The key is appended to the URL as `#k=`. The server never receives this key.
    - **Password Protected:** The snippet is encrypted using a user-provided password as the key. The recipient must enter the password to view the content. Password-protected snippets allow for customizable expiration times.
    - **E2EE Disabled (Plaintext):** Users can opt-out of encryption. In this mode, the snippet is sent to the server as-is. The UI provides a clear warning when this mode is active.
4. **Automatic Expiration:** Snippets are single-use only — once they are successfully viewed, they are removed from the database. All snippets expire automatically 1 hour after creation (or up to 1 week if configured in password mode).
5. **Secure Rendering:** Decrypted content is rendered inside a sandboxed iframe using Trusted Types to prevent XSS.

---

## Usage Instructions

### Creating a Snippet
1. Navigate to `https://snipto.net/{your-slug}`.
2. Enter your content in the text area.
3. Select your protection method using the switcher:
    - **Random Secret:** E2EE using a link-based key (Default).
    - **Password:** E2EE using a password you choose (min 8 characters).
    - **E2EE Disabled:** No encryption (Not recommended for sensitive data).
4. Click **Snipto it**.

### Viewing a Snippet
1. Navigate to the snippet URL.
2. Based on the protection mode:
    - **Random Secret:** The URL must include the `#k=` fragment to decrypt.
    - **Password:** You will be prompted to enter the password.
    - **E2EE Disabled:** The content is displayed immediately.
3. Once viewed, the snippet is immediately removed from the database.

---

## Technical Security Overview

Snipto is designed with end-to-end encryption (E2EE) as a core principle. All encryption and decryption operations occur client-side in the browser using the Web Crypto API.

### Encryption Algorithm
Snippets are encrypted using **AES-256-GCM**. A 12-byte random nonce is generated for each snippet.

### Key Derivation
The application derives encryption and HMAC keys using **PBKDF2-HMAC-SHA256** with 100,000 iterations. The source for derivation is either:
*   A 16-character alphanumeric "short secret" generated client-side (stored in the URL fragment).
*   A user-provided password (never sent to the server).

### Payload Handling
- **Server Storage:** The server stores the base64-encoded encrypted payload (ciphertext + auth tag + HMAC), the nonce, the protection type, and a SHA-256 hash of the secret/password (`key_hash`).
- **Access Control:** The `key_hash` is required to retrieve the encrypted payload from the API. This prevents unauthorized access even if the slug is guessed.
- **Integrity:** AES-GCM and an additional HMAC-SHA256 ensure that any tampering with the ciphertext or nonce is detected during decryption.

### CSP & Frontend Security
Snipto employs a multi-layered defense-in-depth strategy to protect user data and prevent common web attacks.

- **Strict Content Security Policy (CSP):** Using `Spatie\Csp`, the application enforces a strict policy:
    - `default-src 'none'`: All resources are blocked by default.
    - `frame-ancestors 'none'`: Prevents clickjacking.
    - `connect-src 'self'`: Restricts AJAX requests only to the origin server.
    - `require-trusted-types-for 'script'`: Mandates the use of Trusted Types to prevent DOM-based XSS.
    - **Alpine.js CSP:** Uses the CSP-compatible build of Alpine.js to avoid `unsafe-eval`.

- **Cross-Origin Isolation:** Specialized middleware ensures the browser runs in a secure context:
    - `Cross-Origin-Opener-Policy: same-origin` (COOP)
    - `Cross-Origin-Embedder-Policy: require-corp` (COEP)
    - `Cross-Origin-Resource-Policy: same-origin` (CORP)

- **Secure Content Rendering:** 
    - Decrypted snippets are rendered in a **sandboxed `<iframe>`** (`sandbox="allow-same-origin"`) without `allow-scripts`, isolating the content from the main document.
    - Implements a **localized Meta CSP** (`default-src 'none'`) inside the iframe's `srcdoc` to prevent any resource loading or data exfiltration.
    - Implements a **Trusted Types policy** (`snipto-srcdoc`) to sanitize and safely inject the `srcdoc`.

---

## Deployment & Development

### Quick Start (Docker Hub)

The fastest way to run Snipto is using the official image. This method does not require cloning the repository.

```bash
docker run -d \
  --name snipto \
  -p 8080:8080 \
  -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/tmp/database.sqlite \
  tchubaba/snipto:latest
```

Visit `http://localhost:8080` to start sharing snippets.

**Note:** For persistence, it is recommended to mount a volume for the SQLite database so your data survives container updates. For high-traffic environments, you should probably connect to an external MariaDB/MySQL instance.

---

### Local Development (GitHub Clone)

Snipto is Dockerized for both production and development environments.

### Environment Modes

When working with a cloned repository, the default mode is **Development**. The deployment mode is controlled by the `APP_ENV` environment variable in your `.env` file:

- **Development (Default):** Set `APP_ENV=local` (default in `.env.example`). This mode installs dev-dependencies, generates IDE helper files, and starts the Vite development server with hot-module replacement.
- **Production:** Set `APP_ENV=production`. This mode optimizes the application for performance, runs `composer install --no-dev`, caches configurations, and builds production assets.

To switch modes:
1. Update your `.env` file (e.g., `APP_ENV=local` or `APP_ENV=production`).
2. Rebuild and start the containers: `make build && make up`.


### External Database

By default, Snipto starts a MariaDB container. To use an external database instead:

1. Open `config.mk` and remove `with-db` from the `COMPOSE_PROFILES` variable.
2. Update your `.env` file with your external database credentials (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
3. Run `make up`.

The application will automatically wait for your external database to be accessible before running migrations.

### Makefile Shortcuts

A `Makefile` is provided to simplify common tasks:

| Command | Description |
| :--- | :--- |
| `make up` | Start the containers in the background. |
| `make down` | Stop and remove the containers. |
| `make build` | Rebuild the Docker images. |
| `make restart` | Restart all services. |
| `make artisan cmd="..."` | Run an Artisan command (e.g., `make artisan migrate`). |
| `make composer cmd="..."`| Run a Composer command. |
| `make npm cmd="..."` | Run an NPM command. |
| `make shell` | Open a bash shell inside the `app` container. |
| `make test` | Run the PHPUnit test suite. |
| `make grumphp` | Run GrumPHP quality checks. |
| `make fix` | Automatically fix code style issues using Laravel Pint. |
| `make logs` | Tail the application logs. |
| `make fresh` | Reset the database and run migrations with seeders. |

---

## License
This project is licensed under the [MIT License](LICENSE).

---

**Snipto** — Share your snippets. End-to-end encrypted.
