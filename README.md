# Snipto

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/tchubaba/snipto/actions/workflows/test.yml/badge.svg)](https://github.com/tchubaba/snipto/actions/workflows/test.yml)
[![Release](https://img.shields.io/github/v/release/tchubaba/snipto)](https://github.com/tchubaba/snipto/releases)
[![Docker Pulls](https://img.shields.io/docker/pulls/tchubaba/snipto)](https://hub.docker.com/r/tchubaba/snipto)

**Snipto** is a secure, lightweight online sharing service. Users create and share **text snippets and files up to 50 MiB** via unique URLs, with **end-to-end encryption**, ephemeral storage, and QR code integration. Snipto is designed for personal use, teams, and anyone who wants a private alternative to public paste services. Everything — encryption, key derivation, decryption — happens in the browser; the server never sees plaintext, keys, or passphrases.

---

## Features

- **Instant Creation:** No "create" page — simply visit a URL and start typing (or drop a file).
- **End-to-End Encryption (E2EE):** Content is encrypted client-side by default. The server never sees the raw snippet or the encryption key.
- **File Sharing (up to 50 MiB):** Flip the content toggle to File and pick or drag a file. Its **name, type, and contents are encrypted together in your browser** before upload — the server stores one opaque binary blob. Protected modes only; the link is good for exactly **one download**, then it is gone.
- **Optional Password Protection:** Secure your snippets with a personal password.
- **Snipto ID (Asymmetric E2EE):** Generate a public Snipto ID from a passphrase. Anyone can encrypt to it; only you can decrypt — your private key is re-derived from your passphrase in the browser and never transmitted.
- **Customizable Expiration:** Choose how long your snippet stays available (up to 1 week) when using password protection or Snipto ID.
- **Ephemeral:** Snippets are deleted after the first view (files after their single download) and expire automatically after the chosen time (default 1 hour).
- **QR Code Generation:** Generate a QR code upon snippet creation for easy mobile sharing.
- **Security Focused:** Built with Laravel 13 (PHP 8.5+), Alpine.js (CSP-compliant build), and strict Content Security Policy (CSP) headers.

---

## How It Works

1. **Access a Snipto URL:** Visit `https://snipto.net/{slug}`.
2. **Create or View:**
    - If the slug does not exist, you can create a new snippet or file share.
    - If it exists, Snipto identifies the protection mode (and whether the content is text or a file). Password-protected sniptos prompt for the password before the payload is retrieved.
3. **Sharing Modes:**
    - **Random Secret (Default):** The browser generates a random secret and appends it to the URL as `#k=`. The AES-256 key is derived from that secret in the browser (Argon2id + HKDF). The server never receives the secret or the key.
    - **Password Protected:** Encrypted with a user-provided password; the recipient must enter it to view the content. Customizable expiration times.
    - **Snipto ID (Asymmetric):** The sender encrypts to the recipient's published Snipto ID using X25519 ECDH. Only the recipient — who re-derives their private key from their passphrase in the browser — can decrypt. Private keys never leave the browser; the server only sees ciphertext, the sender's ephemeral public key, and a public per-recipient salt.
    - **E2EE Disabled (Plaintext):** An explicit opt-out for non-sensitive convenience sharing — the payload goes to the server as-is, and the UI warns loudly when it is active. Not available for files.
4. **Files:** A file share reserves the slug with a metadata-only request, then uploads the encrypted bytes (name, type, and contents wrapped in one client-side envelope) as a raw binary body. An interrupted upload is invisible and gets reaped after 30 minutes. The viewer unlocks the link exactly like a text snipto, then gets a download card — one download, then the link is consumed.
5. **Automatic Expiration:** Sniptos are single-use — once successfully viewed (or downloaded, for files) they are removed from the database. All sniptos expire automatically 1 hour after creation (or up to 1 week for password-protected and Snipto ID sniptos). A scheduled job also sweeps expired rows every 15 minutes.
6. **Secure Rendering:** Decrypted text renders inside a sandboxed iframe using Trusted Types to prevent XSS. Files are never rendered at all — they are delivered as an opaque download with a sanitized name.

---

## Usage Instructions

### Creating a Snippet
1. Navigate to `https://snipto.net/{your-slug}`.
2. Enter your content in the text area (or switch the content toggle to **File** — see below).
3. Select your protection method using the switcher:
    - **Random Secret:** E2EE using a link-based key (Default).
    - **Password:** E2EE using a password you choose (min 12 characters).
    - **Snipto ID:** Paste the recipient's published Snipto ID. Only they can decrypt.
    - **E2EE Disabled:** No encryption (Not recommended for sensitive data; hidden entirely when sharing a file).
4. Click **Snipto it**.

### Sharing a File
1. Navigate to `https://snipto.net/{your-slug}` and switch the content toggle to **File**. Pick a file (up to 50 MiB) by clicking or dragging it onto the panel; an encrypted upload progress bar tracks the transfer.
2. Choose a protection method — URL Secret, Password, or Snipto ID. **Files are always encrypted**; the E2EE-Disabled mode disappears in File mode because an unencrypted file upload has nothing protecting it.
3. Click **Snipto it**. Your browser encrypts the file — name, type, and bytes together — before anything leaves the device; share the generated link once it appears.
4. The recipient unlocks the link exactly like a text snipto (key fragment, password, or passphrase) and then clicks **Download**. Each file link can be downloaded **once**; after that the link 404s. If an upload is interrupted, the link stays invisible and the unfinished share is deleted within 30 minutes — just start over.

### Generating a Snipto ID
1. Visit `https://snipto.net/sniptoid`.
2. Enter a passphrase (minimum 20 characters) — or click **Generate for me** to roll a diceware passphrase: 6 words from the EFF short wordlist (~62 bits of entropy) in English, or 5 words from a 7,776-word frequency list (~64.6 bits) in other locales.
3. If you used the generator, the passphrase is shown immediately — **copy or memorize it** before continuing: once you derive the ID, the passphrase is wiped from memory.
4. Click **Generate Snipto ID**. Save both the passphrase **and** the resulting 64-character Snipto ID — both are required to decrypt incoming messages.
5. Share your Snipto ID with anyone who wants to send you encrypted snippets or files.

### Viewing a Snippet
1. Navigate to the snippet URL.
2. Based on the protection mode:
    - **Random Secret:** The URL must include the `#k=` fragment to decrypt.
    - **Password:** You will be prompted to enter the password.
    - **Snipto ID:** You will be prompted for the passphrase that generated your Snipto ID.
    - **E2EE Disabled:** The content is displayed immediately.
3. A **file** snipto shows a download card once unlocked — file name, size, and type, all recovered from inside the encrypted blob — with a single **Download** button.
4. Once viewed (or downloaded), the snipto is immediately removed from the database.

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

**Note:** For persistence, it is recommended to mount a volume for the SQLite database so your data survives container updates. For high-traffic environments, you should probably connect to an external MariaDB/MySQL instance. The image ships the PHP limits that 50 MiB file sharing needs (`post_max_size=128M`, `memory_limit=384M`); if you front the container with your own reverse proxy, allow generous request bodies **only** on `PUT /api/snipto/{slug}/payload` — a complete nginx example lives in [DOCKERHUB.md](DOCKERHUB.md).

### Production Deployment Security

For a security-focused application like Snipto, supply chain integrity is critical. While the `latest` tag is provided for convenience, we strongly recommend deploying Snipto using one of the following secure methods:

1. **Pin by Image Digest (Recommended):** Instead of using a mutable tag like `latest` or `v1.2.3`, pin your deployment to the exact `sha256` digest of the image. This guarantees that the image you run is exactly what we published and prevents upstream tampering.
2. **Verify the Image Signature:** Our Docker images are cryptographically signed via Sigstore/Cosign during the GitHub Actions build process. You can verify the integrity and origin of the image using:
   ```bash
   cosign verify \
     --certificate-identity-regexp "https://github.com/tchubaba/snipto/.*" \
     --certificate-oidc-issuer "https://token.actions.githubusercontent.com" \
     tchubaba/snipto:latest
   ```
3. **Build from Source:** For the highest level of trust, clone this repository and build the Docker image yourself. The provided Dockerfiles pin every base image by digest, bake PHP dependencies and built assets into the production image, and use lockfile-based `npm ci` builds.

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

### Production Stack (Nginx + PHP-FPM + CrowdSec)

For real deployments the repository ships a hardened production stack: PHP-FPM behind OpenResty Nginx with HTTPS on port 443, protected by a CrowdSec bouncer (the CrowdSec agent parses the Nginx access logs, detects attacks, and shares ban decisions with the bouncer).

```bash
make prod-build       # Build the FPM image and pull the Nginx/CrowdSec images
make prod-up          # Start the production stack (Nginx :443 → FPM :9000)
make prod-down        # Stop the production stack
```

HTTPS requires a certificate (`cert.pem`) and key (`key.pem`): set `SSL_CERT_DIR=/path/to/certs` to point at them (they are mounted read-only at `/etc/nginx/ssl` inside the Nginx container). Optionally, set `AOP_CERT_DIR=/path/to/aop` to enable **Cloudflare zone-level Authenticated Origin Pulls** — nothing is downloaded from Cloudflare; you generate the material yourself: a CA cert + key and a client cert + key signed by it (the exact `openssl` recipe is in `docker/aop.example/aop.conf`). Upload the client **certificate and private key** to the zone (SSL/TLS → Origin Server → Authenticated Origin Pulls → Upload certificate, then flip the zone-level toggle), and point `AOP_CERT_DIR` at a directory holding that AOP directory's `aop.conf` plus the CA certificate saved as `ssl_client_certificate.pem`. With it set, Nginx verifies a Cloudflare-presented, CA-signed TLS client certificate on every handshake, so direct origin-IP access is rejected at the TLS layer — the Cloudflare IP allowlist remains as defense-in-depth. The private keys never need to reach the origin. With the variable unset (the default), AOP is fully disabled and nothing changes. Run `make crowdsec-setup` once after the first deployment to register the CrowdSec agent and generate the OpenResty bouncer configuration, then copy `CROWDSEC_API_KEY` into your `.env`. `make prod-deploy` additionally regenerates the Cloudflare IP allowlist for Nginx before starting the stack. Utility targets accept `MODE=production` to run inside the production containers (e.g., `make artisan cmd="migrate" MODE=production`).

The shipped stack sizes the file-sharing path end to end: Nginx caps request bodies at 64 MB **on the payload upload route only**, PHP accepts 128 MB posts, and the bundled MariaDB gets `max_allowed_packet=128M` — no extra configuration is needed to support the 50 MiB ceiling.

The app container starts `php artisan schedule:work`, which runs `php artisan sniptos:purge` every 15 minutes to delete expired sniptos (and abandoned file uploads) from the database (expiry is always enforced at read time regardless; the purge is storage hygiene, so payloads at rest don't outlive their nominal expiry by more than one scheduler interval). If you deploy outside the shipped images (bare metal or a custom stack), tick the schedule yourself — add `* * * * * php artisan schedule:run` to a crontab or run `schedule:work` under a supervisor — or schedule `sniptos:purge` directly.

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
| `make fix` | Automatically fix PHP code style issues using php-cs-fixer. |
| `make lint` | Check PHP code style without modifying files. |
| `make logs` | Tail the application logs. |
| `make fresh` | Reset the database and run migrations with seeders. |
| `make prod-build` | Build the production images (PHP-FPM + Nginx). |
| `make prod-up` / `make prod-down` | Start / stop the production stack. |
| `make crowdsec-setup` | One-time CrowdSec agent registration for the production stack. |
| `make prod-deploy` | Refresh the Cloudflare IP allowlist and start the production stack. |

### Browser E2E Tests (Playwright)

A Playwright suite walks the full create → retrieve lifecycle in a real browser for all four protection modes — Plaintext, URL Secret (`#k=` fragment), Password, and Snipto ID (including ID generation on `/sniptoid`) — and pins protocol behaviors such as single-use consumption, wrong-secret retry (the server 403s before burning the snippet), and the guarantee that `key_hash`/`reveal_token` never appear in any request URL. A dedicated file-sharing spec covers byte-exact file round-trips in all three protected modes, drag-and-drop, the 50 MiB limit (client guard and a real 50 MiB round-trip), hostile filenames (extension traps, right-to-left overrides), the upload progress bar, and the interrupted-upload retry/visibility gate.

Every spec runs on **both Chromium and Firefox** (one Chromium-only spec uses Chrome DevTools Protocol throttling and self-skips on Firefox).

Run it locally against the dev stack:

```bash
make up                                 # dev stack on http://localhost:8080
npm install                             # once — installs @playwright/test
npx playwright install --with-deps chromium firefox   # once — downloads the browsers
npm run test:e2e                        # run the suite
```

The suite follows the `APP_PORT` in your local `.env` automatically (falling back to the 8080 default when there is no `.env`). To force a specific origin, set `E2E_BASE_URL`:

```bash
E2E_BASE_URL=http://localhost:9090 npm run test:e2e
```

Handy variants:

```bash
npx playwright test tests/e2e/file.spec.js   # a single spec
npx playwright test --headed                 # watch the browser drive the UI
npx playwright show-report                   # HTML report from the last run
```

The same suite runs in CI (`.github/workflows/test.yml`) on every pull request and every push to `master`, alongside the PHPUnit suite. Specs live in `tests/e2e/`; configuration is in `playwright.config.js`.

---

## Security

The nitty-gritty of how Snipto defends itself lives in its own documents, not in this README:

- **[Threat Model](docs/THREAT_MODEL.md)** — what Snipto protects against, what it explicitly does *not*, and exactly what the server can see. Read this first.
- **[How the Crypto Works](docs/CRYPTO.md)** — implementation-accurate walkthrough: Argon2id (OWASP strong tier), HKDF key splitting, memory-hard `key_hash`, X25519 with full public-key validation (RFC 9496), the file envelope and binary upload protocol.
- **[Security Policy](SECURITY.md)** — how to report a vulnerability.

All encryption happens in your browser. The server stores ciphertext and metadata only — never plaintext, keys, or passphrases — and files arrive as opaque encrypted blobs whose name, type, and contents the server never learns. Docker images are signed with Sigstore/cosign; base images are pinned by digest.

---

## License
This project is licensed under the [MIT License](LICENSE).

---

**Snipto** — Share your snippets. End-to-end encrypted.
