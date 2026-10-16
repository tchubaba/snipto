# Snipto

**Secure, ephemeral, end-to-end encrypted snippet sharing.**

Snipto lets you share text snippets via single-use URLs. Content is encrypted in the browser before it ever leaves the device — the server stores ciphertext only and has no way to read it. Snippets self-destruct after the first view (or after their expiration window).

- End-to-end encrypted (AES-256-GCM client-side; key never touches the server)
- Three protection modes: random link-key, password, or asymmetric **Snipto ID** (X25519 ECDH)
- Memory-hard key derivation (Argon2id) for password and Snipto ID modes
- Built-in diceware passphrase generator with reveal/copy gate
- Single-use, auto-expiring (1 hour default; up to 1 week for password / Snipto ID)
- QR codes for mobile sharing
- Strict CSP, Trusted Types, sandboxed iframe rendering, COOP/COEP/CORP isolation
- Self-contained image — no internet access required at runtime

### Protection modes

- **URL Secret** — random AES-256-GCM key embedded in the URL fragment (`#k=…`). Argon2id (64 MB, t=3) → HKDF-SHA256 → separate AES-GCM and HMAC keys. `key_hash` = HKDF-derived from Argon2id master (memory-hard). Never sent to the server; two-step retrieval: pre-auth GET returns nonce, client derives key_hash, second request retrieves payload.
- **Password** — recipient enters a shared password. Argon2id (64 MB, t=3) → HKDF-SHA256 → separate AES-GCM and HMAC keys.
- **Snipto ID** — publish a 64-character ID derived from your passphrase via X25519 + Argon2id. Senders encrypt to your public key; only your passphrase can decrypt. Private keys are never stored or transmitted — re-derived in the browser on demand.

Built on Laravel 13, PHP 8.5, Alpine.js (CSP build), Tailwind CSS 4.

---

## Quick start

```bash
docker run -d \
  --name snipto \
  -p 8080:8080 \
  -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/snipto.sqlite \
  -v snipto-data:/data \
  tchubaba/snipto:latest
```

Open http://localhost:8080 and start sharing. Set `APP_KEY` once and reuse the same value across container recreations — otherwise existing sessions and signed URLs become invalid (snippet content is encrypted client-side and is unaffected). If you omit `APP_KEY`, one is auto-generated on first boot but it lives in the container's ephemeral `.env` and will rotate on every recreation.

---

## Configuration

| Variable | Default | Notes |
|---|---|---|
| `APP_KEY` | *(auto-generated)* | Use `base64:$(openssl rand -base64 32)` to set explicitly |
| `APP_URL` | `http://localhost:8080` | Set to your public URL when behind a domain |
| `APP_PORT` | `8080` | Internal listen port |
| `APP_DEBUG` | `false` | Keep `false` in production |
| `DB_CONNECTION` | `mysql` | `mysql`, `mariadb`, `pgsql`, `sqlite` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | — | Required for non-SQLite |
| `SESSION_SECURE_COOKIE` | `true` | Set `false` only when testing over plain HTTP |
| `TRUSTED_PROXIES` | *(unset)* | Set **only** when a reverse proxy fronts the container — see [Behind a reverse proxy](#behind-a-reverse-proxy) |

The container runs migrations on boot and waits for the database to be reachable.

### With external MariaDB / MySQL

```bash
docker run -d \
  --name snipto \
  -p 8080:8080 \
  -e APP_URL=https://snipto.example.com \
  -e DB_CONNECTION=mysql \
  -e DB_HOST=db.example.com \
  -e DB_DATABASE=snipto \
  -e DB_USERNAME=snipto \
  -e DB_PASSWORD=secret \
  tchubaba/snipto:latest
```

### Persistence

For SQLite, mount a volume at the path you point `DB_DATABASE` to (as in the quick-start example). For external databases, persistence lives on the database server — no volume needed.

### Behind a reverse proxy

Terminate TLS at your proxy (Caddy, Traefik, nginx) and forward to port `8080`. Set `APP_URL` to your public HTTPS URL and keep `SESSION_SECURE_COOKIE=true`.

Then tell Snipto to believe that proxy for the client address:

```bash
  -e TRUSTED_PROXIES=172.18.0.2   # the proxy's address as the app container sees it
```

Caddy and Traefik send `X-Forwarded-For` on their own. nginx needs it spelled out:

```nginx
location / {
    proxy_pass http://snipto:8080;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

`TRUSTED_PROXIES` takes a single IP, a CIDR, or a comma-separated list. Use the address the **app container sees as the connection peer** — on a shared Docker network that is the proxy's container address, not its public one. Giving the proxy a static `ipv4_address` and trusting exactly that is the tight option; trusting the network's whole CIDR is easier but means any container on that network can assert a client address.

**Never set it to `*`.** That trusts the connection peer whatever it is — including the browser — so a visitor can send any `X-Forwarded-For` they like and get a fresh rate-limit key with every request. The tiered limiter would then be keyed on a value the attacker chose.

### Exposed directly, with no proxy

If you publish `8080` yourself, **leave `TRUSTED_PROXIES` unset.** The client's TCP connection terminates at the container, so `REMOTE_ADDR` is already the real client address and the rate limiter keys on it correctly; a forged `X-Forwarded-For` is simply ignored. Setting the variable in this topology is what would make header spoofing possible.

Three things to know before running this way:

- **No TLS.** The container runs PHP's built-in server, which cannot terminate TLS. Snippet content is encrypted in the browser before it leaves it, but that guarantee depends on the browser running untampered JavaScript — over plain HTTP an on-path attacker can rewrite the page and capture a passphrase or password *before* encryption happens. Plain HTTP is for LAN use and testing; anything routable should sit behind a TLS-terminating proxy.
- **One request at a time.** `PHP_CLI_SERVER_WORKERS` is not set, so the built-in server serialises requests.
- **8 MB request cap.** PHP's default `post_max_size` is `8M` while the API itself allows 10 MB, so uploads between 8 and 10 MB are rejected with `413`. A `PHP_POST_MAX_SIZE` environment variable does not fix it — `post_max_size` is a per-directory setting, so mount an ini file instead:

  ```bash
  # uploads.ini contains:  post_max_size=12M
  docker run -d ... -v ./uploads.ini:/usr/local/etc/php/conf.d/uploads.ini:ro ...
  ```

  Or front the container with a proxy that buffers the body.

---

## Tags

- `latest` — most recent stable release
- `vX.Y.Z` — pinned version (e.g. `v1.2.2`)

### Secure Deployment (Recommended)

For maximum security, we strongly recommend deploying Snipto using the image's immutable `sha256` digest rather than mutable tags like `latest`.

Our images are cryptographically signed using Sigstore/Cosign. You can verify the integrity and origin of the image using:

```bash
cosign verify \
  --certificate-identity-regexp "https://github.com/tchubaba/snipto/.*" \
  --certificate-oidc-issuer "https://token.actions.githubusercontent.com" \
  tchubaba/snipto:latest
```

---

## Source & support

- **Source:** https://github.com/tchubaba/snipto
- **Issues:** https://github.com/tchubaba/snipto/issues
- **License:** MIT

> The image is production-only by design. For local development with hot reload, Xdebug, and dev tooling, clone the repo and use the included `Makefile`.
