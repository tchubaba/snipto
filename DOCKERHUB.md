# Snipto

**Secure, ephemeral, end-to-end encrypted snippet sharing.**

Snipto lets you share text snippets via single-use URLs. Content is encrypted in the browser before it ever leaves the device — the server stores ciphertext only and has no way to read it. Snippets self-destruct after the first view (or after their expiration window).

- End-to-end encrypted (AES-256-GCM client-side; key never touches the server)
- Three protection modes: random link-key, password, or asymmetric **Snipto ID** (X25519 ECDH)
- Single-use, auto-expiring (1 hour default; up to 1 week for password / Snipto ID)
- QR codes for mobile sharing
- Strict CSP, Trusted Types, sandboxed iframe rendering, COOP/COEP/CORP isolation
- Self-contained image — no internet access required at runtime

Built on Laravel 13, PHP 8.5, Alpine.js (CSP build), Tailwind CSS 4.

---

## Quick start

```bash
docker run -d \
  --name snipto \
  -p 8080:8080 \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=/data/snipto.sqlite \
  -v snipto-data:/data \
  tchubaba/snipto:latest
```

Open http://localhost:8080 and start sharing. An `APP_KEY` is generated automatically on first boot if you don't provide one.

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

---

## Tags

- `latest` — most recent stable release
- `vX.Y.Z` — pinned version (e.g. `v1.2.2`)

---

## Source & support

- **Source:** https://github.com/tchubaba/snipto
- **Issues:** https://github.com/tchubaba/snipto/issues
- **License:** MIT

> The image is production-only by design. For local development with hot reload, Xdebug, and dev tooling, clone the repo and use the included `Makefile`.
