# Snipto

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/tchubaba/snipto/actions/workflows/test.yml/badge.svg)](https://github.com/tchubaba/snipto/actions/workflows/test.yml)
[![Release](https://img.shields.io/github/v/release/tchubaba/snipto)](https://github.com/tchubaba/snipto/releases)
[![Docker Pulls](https://img.shields.io/docker/pulls/tchubaba/snipto)](https://hub.docker.com/r/tchubaba/snipto)

**Snipto** is a secure, lightweight online sharing service. Users create and share **text snippets and files up to 50 MiB** via unique URLs, with **end-to-end encryption**, ephemeral storage, and QR code integration. Everything (encryption, key derivation, decryption) happens in the browser; the server never sees plaintext, keys, or passphrases.

---

## Features

- **Instant Creation:** No "create" page: simply visit a URL and start typing (or drop a file).
- **End-to-End Encryption (E2EE):** Content is encrypted in the browser by default; the server only ever stores ciphertext. The four sharing modes are described under How It Works.
- **File Sharing (up to 50 MiB):** Name, type, and contents are encrypted together in your browser before upload; the server stores one opaque binary blob. Protected modes only, and the link is good for exactly **one download**.
- **Snipto ID (Asymmetric E2EE):** Publish an ID derived from a passphrase; anyone can encrypt to it, only you can decrypt.
- **Ephemeral:** Deleted after the first view (files after their single download) and auto-expiring after the chosen window (default 1 hour, up to 1 week for password-protected and Snipto ID sniptos).
- **QR Code Generation:** QR code on creation for easy mobile sharing.

---

## How It Works

1. **Access a Snipto URL:** Visit `https://snipto.net/{slug}`.
2. **Create or View:**
    - If the slug does not exist, you can create a new snippet or file share.
    - If it exists, Snipto identifies the protection mode (and whether the content is text or a file). Password-protected sniptos prompt for the password before the payload is retrieved.
3. **Sharing Modes:**
    - **URL Secret (Default):** The browser generates a random secret and appends it to the URL as `#k=`. The AES-256 key is derived from that secret in the browser (Argon2id + HKDF). The server never receives the secret or the key.
    - **Password Protected:** Encrypted with a user-provided password; the recipient must enter it to view the content. Customizable expiration times.
    - **Snipto ID (Asymmetric):** The sender encrypts to the recipient's published Snipto ID using X25519 ECDH. Only the recipient, who re-derives their private key from their passphrase in the browser, can decrypt. Private keys never leave the browser; the server only sees ciphertext, the sender's ephemeral public key, and a public per-recipient salt. Recipients generate an ID on `/sniptoid` and must save both the passphrase and the ID: together they are the key, and the passphrase is wiped from the page once the ID is derived.
    - **E2EE Disabled (Plaintext):** An explicit opt-out for non-sensitive convenience sharing: the payload goes to the server as-is, and the UI warns loudly when it is active. Not available for files.
4. **Files:** A file share reserves the slug with a metadata-only request, then uploads the encrypted bytes (name, type, and contents wrapped in one client-side envelope) as a raw binary body. An interrupted upload is invisible and gets reaped after 30 minutes. The viewer unlocks the link exactly like a text snipto, then gets a download card: one download, then the link is consumed.
5. **Automatic Expiration:** Sniptos are single-use: once successfully viewed (or downloaded, for files) they are removed from the database. All sniptos expire automatically 1 hour after creation (or up to 1 week for password-protected and Snipto ID sniptos). A scheduled job also sweeps expired rows every 15 minutes.
6. **Secure Rendering:** Decrypted text renders inside a sandboxed iframe using Trusted Types to prevent XSS. Files are never rendered at all; they are delivered as an opaque download with a sanitized name.

---

## Self-Hosting

The [Docker Hub image](https://hub.docker.com/r/tchubaba/snipto) is the primary self-hosting path. Full instructions, the environment variable reference, and reverse-proxy guidance (including an nginx example for 50 MiB uploads) live in [DOCKERHUB.md](DOCKERHUB.md). Quickest start:

```bash
docker run -d \
  --name snipto \
  -p 8080:8080 \
  -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/tmp/database.sqlite \
  tchubaba/snipto:latest
```

Open `http://localhost:8080`. Set `APP_KEY` once and keep it stable across container recreations; omit it and a generated key lives only in the container's ephemeral `.env` and rotates on every recreation. Mount a volume for SQLite persistence, and use an external MariaDB/MySQL for anything high-traffic (it needs `max_allowed_packet >= 128M` for file payloads).

### Image Trust

The `latest` tag is for convenience; pin your deployment to the image's `sha256` digest so what you run is provably what was published. Images are signed with Sigstore/cosign at build time:

```bash
cosign verify \
  --certificate-identity-regexp "https://github.com/tchubaba/snipto/.*" \
  --certificate-oidc-issuer "https://token.actions.githubusercontent.com" \
  tchubaba/snipto:latest
```

For the highest level of trust, build from source: every base image is pinned by digest and assets are lockfile-exact (`npm ci`).

---

## Development

The repository is fully Dockerized. For a dev loop, `make build && make up` starts the app with Vite hot-module replacement (`APP_ENV=local`, the `.env.example` default); set `APP_ENV=production` for the optimized build. One `Makefile` drives everything:

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

To drop the bundled MariaDB container in favor of an external database, remove `with-db` from `COMPOSE_PROFILES` in `config.mk`, put your credentials in `.env`, and run `make up`.

### Production Stack (Nginx + PHP-FPM + CrowdSec)

For deploying from source, the repository ships a hardened stack: PHP-FPM behind OpenResty Nginx with HTTPS on 443, fronted by a CrowdSec bouncer.

```bash
make prod-build       # Build the FPM image and pull the Nginx/CrowdSec images
make prod-up          # Start the stack (Nginx :443 → FPM :9000)
make prod-down        # Stop the stack
```

Point `SSL_CERT_DIR` at your `cert.pem`/`key.pem`. Optionally point `AOP_CERT_DIR` at Cloudflare zone-level Authenticated Origin Pulls material (a CA you own plus a client cert signed by it; the openssl recipe is in `docker/aop.example/aop.conf`) so direct origin-IP access is rejected at the TLS layer. Run `make crowdsec-setup` once to register the CrowdSec agent. The stack is pre-sized for the full 50 MiB file path (nginx body caps, PHP limits, `max_allowed_packet`), and its containers run the expiry purge scheduler; deployments outside the shipped images must tick `php artisan schedule:run` themselves or the sweep of expired rows never fires. `make prod-deploy` is the snipto.net deployment target (it refreshes the Cloudflare IP allowlist first); self-hosters generally want plain `make prod-up`.

### Browser E2E Tests (Playwright)

The suite drives real browsers (both Chromium and Firefox) through every protection mode and the file upload/download flows, including hostile filenames and the interrupted-upload gate, and pins protocol guarantees like single-use consumption and secrets never appearing in request URLs. Specs live in `tests/e2e/`; run them against the dev stack:

```bash
make up                                          # dev stack on http://localhost:8080
npm install                                      # once: installs @playwright/test
npx playwright install --with-deps chromium firefox   # once: downloads the browsers
npm run test:e2e                                 # run the suite
```

The suite follows `APP_PORT` from your `.env` automatically; override the origin with `E2E_BASE_URL=http://localhost:9090 npm run test:e2e`. The same suite runs in CI on every pull request and every push to `master`.

---

## Security

The nitty-gritty of how Snipto defends itself lives in its own documents, not in this README:

- **[Threat Model](docs/THREAT_MODEL.md)**: what Snipto protects against, what it explicitly does *not*, and exactly what the server can see. Read this first.
- **[How the Crypto Works](docs/CRYPTO.md)**: implementation-accurate walkthrough: Argon2id (OWASP strong tier), HKDF key splitting, memory-hard `key_hash`, X25519 with full public-key validation (RFC 9496), the file envelope and binary upload protocol.
- **[Security Policy](SECURITY.md)**: how to report a vulnerability.

---

## License
This project is licensed under the [MIT License](LICENSE).
