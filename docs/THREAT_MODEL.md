# Snipto Threat Model

*Last reviewed: October 2026, against v1.16.0. Every claim below is traceable to code in this
repository; file references are given so you can verify them yourself.*

This document states, as plainly as we can, what Snipto protects against, what it does
**not** protect against, and who you have to trust to use it. A threat model that hides its
weaknesses isn't a threat model; it's marketing.

---

## 1. Design goals

1. **Zero-knowledge server.** The server stores ciphertext and metadata only. For encrypted
   modes it never sees the plaintext, the encryption key, the passphrase, or the URL secret.
2. **Ephemeral by default.** Snippets are single-use and expire automatically (1 hour
   default; up to 1 week for password / Snipto ID modes).
3. **No accounts, no signup.** The only "identity" is a slug and, optionally, a secret the
   server never sees.
4. **Self-hostable.** Anyone can run their own instance; the trust anchor can be your own
   hardware.

---

## 2. Actors and trust boundaries

| Actor | Trusted? | Notes |
|---|---|---|
| **Sender** (creates the snipto) | Semi | Trusts their own browser and the JS it downloads (see §6.1) |
| **Recipient** (opens the snipto) | Semi | Same as above |
| **Server operator** (you, or whoever runs an instance) | **Not trusted** for content | The whole design assumes the operator is hostile or compromised |
| **Network eavesdropper** | Not trusted | HTTPS everywhere; secrets travel in POST bodies or URL fragments, never in query strings |
| **Anyone holding the link** | Not trusted | The URL alone is not enough for encrypted modes: the key lives in the `#k=` fragment (never sent to the server) or in the recipient's head |
| **The browser itself / installed extensions** | Out of scope | See §6 |

The critical trust boundary is **the browser ↔ everything else**. Encryption and decryption
happen entirely in the browser (`resources/js/snipto.js`); the server is a dumb, hostile
ciphertext store.

---

## 3. What the server can see (full disclosure)

For an **encrypted** snipto, the database row contains (`app/Models/Snipto.php`,
`ApiController::store()`):

- the **slug** (the locator: treat it as a capability for Plaintext mode, an identifier for encrypted modes)
- the **ciphertext** envelope: `base64(ciphertext ‖ GCM-tag ‖ HMAC)`: text payloads, max 10 MB
- **or**, for a file snipto (`kind = file`): the same framing stored as raw bytes in a
  `LONGBLOB` column (≤ 50 MiB), plus a SHA-256 **upload-token hash** and its 30-minute
  expiry. The file's name, type, and size are *inside* the ciphertext; they are never sent
  to the server in any form (`docs/CRYPTO.md` §3.1)
- the **nonce** (24 hex chars; an IV, not a secret)
- the **protection type**
- a **`key_hash`**: a memory-hard authorization token (see `docs/CRYPTO.md` §7); *not* the encryption key, and not reversible to it without paying full Argon2id cost
- for Snipto ID mode: the sender's **ephemeral public key** and the recipient's **public salt**, both public by design
- expiry timestamp, view counter, and a one-time reveal-token hash

The operator **cannot** see: the plaintext, the AES key, the HMAC key, the passphrase, the
URL secret, or the private key for a Snipto ID.

Request-level visibility (nginx access log, `docker/nginx.conf`): client IP, request path,
timestamp, status, response size, User-Agent, Referer. Note that:

- **URL fragments never reach the server**: the `#k=` key is never in any log, ever.
- `key_hash` and `reveal_token` travel only in **POST bodies** (`POST /api/snipto/{slug}/reveal`), never in URLs, where access logs would capture them.
- The operator can see **ciphertext length** (a weak but real signal: a 20-byte password and a 2 MB log look different), creation/reveal timing, and how many times a slug was probed. For a file, that length is visible twice (reserve row + raw upload body); nothing else about the file is.

Expired rows are deleted by `php artisan sniptos:purge` every 15 minutes (scheduler
registered in `bootstrap/app.php`), so payloads at rest don't outlive their nominal expiry
by more than one scheduler interval. Expired-as-not-found responses also avoid leaking that
a snipto *used* to exist at a slug.

File sniptos add one lifecycle rule: an upload that never landed is **not** a half-visible
object: the slug 404s on every endpoint until the payload arrives, and abandoned
reservations are swept by the same purge job 30 minutes after they were made. So a failed
upload cannot be used to squat slugs or park storage indefinitely.

---

## 4. Threats and mitigations

### T1: Passive network eavesdropper
**Mitigation:** HTTPS (HSTS in the shipped nginx config), secrets in POST bodies or URL
fragments only. Fragments are never transmitted; query strings are never used for
credentials.

### T2: Slug guessing / enumeration
The slug is the locator. Mitigations (`ApiController::store()` validation +
`config/tiered-throttle.php`):
- charset `[A-Za-z0-9_-]+`, min length 8 for Plaintext (where the slug *is* the only
  secret) and 4 for encrypted modes;
- **tiered rate limiting** on every probe: 20/min → 10/2min → 5/5min → **12-hour lockout**,
  keyed on the real client IP;
- even on a correct slug guess, an encrypted snipto reveals **nothing** without the
  `key_hash`: the metadata GET returns only bootstrap fields, and the reveal endpoint 403s
  without a matching `key_hash` (compared with `hash_equals()`, timing-safe).

### T3: Brute-forcing the `key_hash` (server DB exfiltration or online guessing)
`key_hash` is derived from the same Argon2id master key as the ciphertext (memory 64 MB,
t=3, p=1 per guess). An attacker who steals the database pays the identical memory-hard cost
to crack `key_hash` as to crack the payload, so the authorization token adds no cheaper
oracle. Per-snippet salts (derived from the nonce, domain-separated) defeat rainbow tables
and cross-snipto deduplication. Online guessing additionally burns a one-time reveal token
per attempt and re-enters the throttle tiers each time.

### T4: Link prefetchers, crawlers, and URL reputation scanners "burning" a single-use link
The metadata probe (`GET /api/snipto/{slug}`) is **side-effect free**: it never returns the
payload and never decrements the view counter. Only `POST …/reveal` with a valid one-time
`reveal_token` (and `key_hash` for encrypted modes) consumes a view. A scanner that GETs the
link cannot destroy it.

### T5: A malicious or compromised server operator
For encrypted modes the operator sees only ciphertext and metadata (§3). They cannot decrypt
content, cannot forge a reveal without the client presenting `key_hash`, and cannot mint new
sniptos on a user's behalf. A hostile operator *can*: deny service, censor slugs, or serve
tampered JavaScript; see §6.1 for the honest limits of that.

### T6: A malicious sender attacking the recipient (Lim / small-order / twist attacks)
The recipient's Snipto ID private key is long-lived, and the server supplies the sender's
`sender_public_key`: a hostile sender could ship a small-order or twist-curve point to
recover the recipient's private key from the observed `key_hash` (an offline oracle).
Mitigation: **full X25519 public-key validation before any ECDH**:
`x25519PeerKeyValid()` in `resources/js/snipto.js` checks `[order]P = identity` (RFC 9496)
with a BigInt Montgomery ladder, on *both* the sender path (the typed recipient ID) and the
recipient path (the server-supplied key), plus the RFC 7748 §6.1 all-zero shared-secret
check. Pinned by RFC 7748 test vectors and cross-checked against libsodium in
`tests/js/x25519-validation.test.mjs` (CI); reject path covered by the trap-key E2E test in
`tests/e2e/sniptoid.spec.js`.

### T7: Ciphertext / metadata tampering in transit or at rest
Double integrity: AES-256-GCM's auth tag **plus** an independent HMAC-SHA256 over
`ciphertext ‖ tag ‖ nonce` (separate HKDF-derived HMAC key). The client verifies the HMAC
(timing-safe compare) **before** attempting decryption; tampered payloads never decrypt.

### T8: XSS / DOM-based exfiltration in the viewer
- Strict CSP: `default-src 'none'`, `require-trusted-types-for 'script'`, inline scripts and
  styles allowed only with per-request CSP nonces (Alpine CSP build; only `wasm-unsafe-eval`
  for libsodium).
- Decrypted content renders inside a `srcdoc` iframe sandboxed to exactly
  `allow-same-origin` (`allow-scripts` deliberately omitted; the parent uses the shared
  origin only to read `contentDocument.scrollHeight` and re-apply the light/dark text
  color) with its own `default-src 'none'; style-src 'nonce-…'` meta CSP. Snippet
  content is inert: it cannot run scripts, load resources, or read anything from the
  parent page. The one non-script channel left open is inert form submission (CSP's
  `default-src` does not cover `form-action`): a snippet could render a form targeting
  this origin, but with no scripts it can never touch the `reveal_token` or payload.
- COOP/COEP/CORP isolation; CORS disabled outright (`config/cors.php` paths `[]`), so no
  cross-origin API access is possible even from a malicious site.
- **Files are never rendered at all.** A file reveal is delivered as an opaque blob and the
  UI offers it only as a download with a hardcoded `application/octet-stream` type: the
  stored MIME string is recovered from inside the ciphertext for display next to the button
  and is never echoed into any header, so nothing a sender stored can execute. The filename
  is aggressively sanitized before it is displayed or used as the download name: path
  separators and control characters (C0 and C1 ranges) are stripped, Unicode directional
  overrides that could spoof a visible extension (RTL/LTR overrides, isolates) are removed,
  and empty/dot-only/reserved names fall back to `download`. `report.pdf.exe`, `..\evil`,
  and right-to-left-reversed names all arrive inert.

### T9: Replay / token theft of the reveal credential
The `reveal_token` is single-use (burned on **any** attempt, right or wrong), TTL 15 minutes
(never outliving the snipto), stored only as a SHA-256 hash, and overwritten by each new
metadata probe. A stolen token is useless after one attempt; a stolen `key_hash` is
useless without a fresh valid token. Both live only in POST bodies and response bodies,
never in URLs. The file-upload credential follows the same discipline: `store()` hands out a
one-time 64-hex `upload_token`, the server keeps only its SHA-256 hash with a 30-minute TTL,
presentation is checked (`hash_equals`) *before* the body is read, and the token is burned
the moment a payload lands: a second upload under the same token 403s, and a completed row
404s on the payload route. A re-`POST` that presents the still-valid token keeps it (that is
what makes same-tab retry work); one that cannot is refused before the existing row is
touched.

### T10: Denial of service / spam creation
Tiered throttles on every snippet endpoint **including the file-upload `PUT`** (store:
10/min → 5/5min → 12h lockout; a dedicated upload tier with the same escalation shape),
payload caps (10 MB text / 50 MiB file ciphertext), single forced view per snipto, bounded
storage pressure because unfinished uploads are invisible *and* reaped after 30 minutes, and
the shipped production stack fronts everything with a CrowdSec bouncer at the nginx layer:
where `client_max_body_size 64M`, scoped to the payload route only, rejects oversize bodies
before PHP or the database ever see them.

### T11: Supply-chain compromise of the deployment
Docker images are signed with **Sigstore/cosign** in CI and base images pinned by digest;
`composer install`/`npm ci` are lockfile-based; commits are GPG-signed. Verify with the
`cosign verify` command in the README, or build from source.

---

## 5. Abuse (the honest part)

Ephemeral, single-use, encrypted links are attractive for dropping malicious payloads.
Snipto's posture:

- short lifetimes and single-use limit (but do not eliminate) abuse windows;
- rate limits and CrowdSec make mass-spraying expensive;
- the operator (if you self-host, that's you) can see slugs, timing, and ciphertext sizes:
  enough to cooperate with abuse reports, not enough to read content;
- the public instance's abuse contact is on the [contact page](https://snipto.net/contact).

We will not pretend abuse is impossible; we make it cheap to detect at the metadata level
and expensive to scale.

---

## 6. Explicitly out of scope / known limitations

Read this section. It is the part most privacy tools leave out.

### 6.1 A malicious server can serve malicious JavaScript
This is the fundamental limit of **web-based** E2EE: the server delivers the code that does
the encrypting. A hostile operator could serve a modified `snipto.js` that exfiltrates keys.
No CSP can stop same-origin code from making same-origin requests. Mitigations, none
perfect:
- the code is open source and CI-pinned; you can audit exactly what runs;
- releases are tagged, commits GPG-signed, Docker images cosign-signed and pinned by digest;
- the ultimate mitigation is **self-hosting**: run your own instance and the untrusted
  operator becomes you.

(For threat-model completeness: a companion browser extension that performs all crypto and
renders decrypted content inside its own isolated `chrome-extension://` pages, reducing the
website to a ciphertext transport and closing this hole completely, is designed and on the
roadmap, not yet built. The design notes live in our private task tracker, not in this
repository, so this claim is not verifiable from source here; ask us about it.)

### 6.2 Metadata and traffic analysis
The server sees who creates and reveals which slug, when, from which IP. Snipto protects
*content*, not *who-talked-to-whom-when* metadata.

### 6.3 Plaintext mode
With E2EE disabled, the payload sits in the database in the clear and the slug is the only
secret. The UI warns loudly, the mode is disabled for short slugs, and mode 0 is a
convenience, not a security feature. Don't use it for secrets.

### 6.4 The endpoint
A keylogger, a compromised browser, or a malicious extension on the sender's or recipient's
machine defeats everything. If the endpoint is hostile, no client-side crypto survives.

### 6.5 Passphrase strength
Password mode (min 12 chars) and Snipto ID (min 20 chars) are only as strong as the
passphrase. Argon2id raises the per-guess cost; it does not resurrect `correcthorsebattery…`.
The built-in diceware generator (~62-64.6 bits) exists precisely because humans are bad at
this.

### 6.6 Legal process against the operator
A court can compel the operator to hand over the database and logs. That yields ciphertext,
`key_hash` values, slugs, and IPs, not plaintext (for encrypted modes), but metadata may
still be sensitive. Self-host if this matters to your threat model.

### 6.7 Not (yet) formally audited
The design has been through an internal pentest round and a security-headers audit; their
findings are cited and fixed in code comments: pentest items as `F-001`, `F-002`,
`F-003`, `F-005`, `AUTHZ-007`, `AUTHZ-008` and `INJ-002`; audit items as `F-1`-`F-5`
(every one of those appears in a code comment, `F-4` in `docker/nginx.conf`). The two
numbering schemes cross exactly once: pentest `F-004` (expiry sweeping) was deferred by
operator decision at remediation time, survives only as a pointer in a migration comment,
and was later closed under the audit scheme as `F-3` (`sniptos:purge`). The crypto
primitives are standard and test-pinned; but no independent third-party audit has been
performed. If you find a bug, see [SECURITY.md](../SECURITY.md).

### 6.8 Malicious file content

Snipto protects files *in transit*; it does not vouch for what they contain. A file that
arrives through a Snipto link is only as trustworthy as the sender who uploaded it: E2EE
proves nobody tampered with it in between, not that it is safe to open. The edges are
hardened (opaque octet-stream delivery, never rendered, sanitized download names (see T8), but
deciding whether to open a file stays the recipient's job: confirm unexpected files over a
second channel, scan with antivirus. Self-hosting does not change this either way.

---

## 7. Summary table

| Threat | Covered? |
|---|---|
| Network eavesdropping | Yes |
| Server sees content/keys (encrypted modes) | Yes |
| DB exfiltration → content | Yes (ciphertext + memory-hard key_hash) |
| DB exfiltration → metadata | Partial: visible by design |
| Slug guessing | Yes (length floors + tiered throttle + key_hash) |
| Link prefetchers burning snippets | Yes (side-effect-free probe) |
| Malicious sender → recipient key recovery (Lim) | Yes (full X25519 validation) |
| Ciphertext tampering | Yes (GCM + HMAC, verify-before-decrypt) |
| XSS / framed exfiltration | Yes (CSP, Trusted Types, sandbox) |
| Hostile operator serving tampered JS | Partial: audit + signatures + self-host |
| Endpoint compromise | No, out of scope |
| Metadata / traffic analysis | No, out of scope |
| Plaintext mode | Partial: convenience mode, no crypto |
| File upload abuse / storage pressure | Yes (50 MiB cap, upload tier, invisible + reaped half-uploads) |
| Filename spoofing / inline rendering of files | Yes (sanitized names, opaque octet-stream, never rendered) |
| Downloaded file content is malicious | No, out of scope: trust the sender, scan the download |
| Formal third-party audit | Pending |

*Questions or corrections: open an issue or see [SECURITY.md](../SECURITY.md).*
