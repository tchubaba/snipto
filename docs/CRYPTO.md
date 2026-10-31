# How Snipto's Cryptography Works

*A complete, implementation-accurate walkthrough. Every parameter below is copied from the
code, not from a design doc that drifted from it — the relevant functions are linked so you
can check each claim line by line. If anything here disagrees with the code, the code wins
and we want to hear about it.*

---

## 1. Design goals

1. **Zero-knowledge:** the server stores ciphertext and public bootstrap values only. Keys,
   passphrases, and URL secrets are born and die inside the browser.
2. **Memory-hard everywhere:** every path from a human secret to a key — and to the
   server-facing authorization token — passes through Argon2id at the OWASP strong tier.
3. **Identity without accounts:** the Snipto ID mode gives you a public, email-like
   identifier that others can encrypt to, with a private key that never exists anywhere
   except transiently in your browser.
4. **Defense against the exotic, not just the ordinary:** full X25519 public-key validation,
   domain-separated salts, identity-bound HKDF labels, verify-before-decrypt, timing-safe
   comparisons throughout.

---

## 2. Primitives

| Purpose | Primitive |
|---|---|
| Authenticated encryption | AES-256-GCM (12-byte nonce, 128-bit tag) — WebCrypto |
| Secondary integrity | HMAC-SHA256 (independent key) — WebCrypto |
| Password / secret hashing | Argon2id (`ARGON2ID13`), 32-byte output — libsodium (WASM) |
| Key splitting | HKDF-SHA256 — WebCrypto |
| Key agreement | X25519 — libsodium (`crypto_scalarmult`) |
| Public-key validation | BigInt Montgomery ladder, `[order]P = identity` (RFC 9496) |
| Salt derivation | SHA-256 with domain-separation prefixes — WebCrypto |
| RNG | `crypto.getRandomValues` everywhere; rejection sampling (never modulo) for strings |

**Argon2id parameters (all modes, identical):** memory = 64 MiB, `opsLimit` = 3,
parallelism = 1, output = 32 bytes. This is the OWASP "strong" recommendation; 64 MB per
guess per thread makes GPU/ASIC precomputation economically brutal, which is what lets Snipto
keep passphrase minimums usable (12 chars for Password, 20 for Snipto ID) without
catastrophic attacker advantage.

Why libsodium (WASM) instead of WebCrypto for Argon2id/X25519? Firefox-based privacy
browsers (Tor, Mullvad Browser) block `crypto.subtle.exportKey('jwk', …)` for X25519 under
`privacy.resistFingerprinting`. Same algorithms, same outputs, broader compatibility. A
single `supportsLibsodium()` probe gates the **Snipto ID mode** with an "update your
browser" fallback (banner on `/sniptoid`, Mode-3 button hidden on the main page); Modes
1–2 need libsodium (`crypto_pwhash`) too, at submit/decrypt time, but are not behind
that probe.

---

## 3. The envelope (all encrypted modes)

Every stored payload is `base64( ciphertext ‖ GCM-tag(16) ‖ HMAC-SHA256(32) )`
(`encryptPayload` / `decryptPayload` in `resources/js/snipto.js`):

- AES-256-GCM encrypts with a fresh 12-byte random nonce (hex-encoded as 24 chars, stored
  server-side as `nonce`).
- The HMAC is computed over `ciphertext ‖ tag ‖ nonceHex` under a **separate** HKDF-derived
  key, and is verified (timing-safe compare) **before** AES-GCM decryption is even attempted.
  The GCM tag alone would suffice for confidentiality+integrity; the second MAC is
  belt-and-braces against implementation error in either layer.

The nonce doubles as the per-snipto KDF salt input (see below), which is why the server
returns it in the pre-auth metadata response: it's an IV/salt, not a secret.

---

## 4. Mode 1 — URL Secret (default)

The key material is a random secret that the server never sees, carried in the URL
**fragment** (`#k=…`) — fragments are never transmitted to any server, never logged, never
in `Referer`.

1. **Secret:** 21 characters from `[A-Za-z0-9]` (≈125 bits), generated with rejection
   sampling (`generateShortSecret`) — bytes ≥ 248 are discarded so there is zero modulo bias.
2. **KDF salt:** `SHA-256("snipto-urlsecret-v1" ‖ nonceHex)[0:16]` — domain-separated so this
   mode's salts can never collide with another mode's.
3. **Master:** `Argon2id(secret, salt, m=64MiB, t=3, p=1) → 32 bytes`.
4. **Split (HKDF-SHA256, salt = `nonceHex`):**
   - `info="aes-key"` → AES-256-GCM key
   - `info="hmac-key"` → HMAC-SHA256 key
   - `info="key-hash"` → the server-facing `key_hash` (hex)
5. The URL becomes `https://host/{slug}#k={secret}`. Only the sender's URL carries the key;
   the server stores ciphertext, nonce, and `key_hash`.

The Argon2id step here is deliberate: even though the 21-char secret already has ~125 bits,
running it through a memory-hard KDF means the `key_hash` the server holds costs the same
64 MB/guess to attack as the ciphertext would (§7).

## 5. Mode 2 — Password Protected

Identical to Mode 1 except: the secret is your password (min 12 chars, enforced client-side),
the salt prefix is `"snipto-password-v2"`, and the recipient learns the nonce from the
metadata probe before deriving. The password never leaves the browser; the server sees only
`key_hash` comparisons.

Because `key_hash` depends on the nonce, retrieval is two-step for Modes 1–2:
`GET /api/snipto/{slug}` returns `{protection_type, nonce, reveal_token}` — never the
payload — then the client derives and `POST`s `{reveal_token, key_hash}` to
`/api/snipto/{slug}/reveal`. Both modes spend exactly one `GET` (the page-load probe)
plus this one `POST` per view: the probe hands the viewer the nonce and a
`reveal_token` that stays valid until a reveal attempt burns it. Only a *failed*
attempt forces a re-probe (a fresh `GET`) before the retry can post again.

## 6. Mode 3 — Snipto ID (asymmetric)

The interesting one: an **identity** you publish once, so anyone can encrypt to you with no
key exchange, no shared secret, and no account.

### 6.1 Identity generation (`/sniptoid`, `resources/js/sniptoid.js`)
1. Roll a fresh 16-byte random salt (`crypto.getRandomValues`).
2. `privateKey = Argon2id(passphrase, salt, m=64MiB, t=3, p=1)` → 32 raw bytes — used
   directly as the X25519 private key. (The raw bytes are *not* pre-clamped — a random 32-byte
   string satisfies the clamping constraints only rarely — but libsodium's `crypto_scalarmult`
   clamps the scalar internally, so feeding it `crypto_pwhash` output directly is safe.)
3. `pubkey = crypto_scalarmult_base(privateKey)`.
4. **Snipto ID = `base64(salt(16) ‖ pubkey(32))`** → 64 chars, no padding.

The salt is *published inside the ID* and stored per-snipto as `recipient_salt`. That's by
design: it makes every Snipto ID its own 64 MB/guess keyspace (rainbow tables die) while
leaking nothing an attacker doesn't already have — the salt is public either way. It also
means the recipient only needs **passphrase + saved ID** to regenerate the keypair; the
private key is never stored, never transmitted, and is `memzero`'d after use.

Generation is intentionally **not deterministic** from the passphrase: the same passphrase
rolls a new salt (and thus a new ID) each time. Save the ID; passphrase + ID regenerate the
keypair.

### 6.2 Sending (sender's browser)
1. Parse the recipient's ID → `recipient_salt ‖ recipient_pubkey`.
2. **Validate the recipient's public key** (§8) — reject before paying any ECDH.
3. Generate an **ephemeral** X25519 keypair (fresh per snipto).
4. `shared = crypto_scalarmult(ephemeral_priv, recipient_pub)`.
5. Keys via HKDF-SHA256, salt = `"snipto-enc-v1"`, and — this is the part most
   implementations skip — the HKDF `info` binds **both public keys**:
   `info = "aes-key" ‖ sorted(my_pub, their_pub)` (likewise `"hmac-key"`). Key-substitution
   resistance: a different keypair pair derives different keys, full stop.
6. `key_hash = SHA-256(shared)` — sent to the server as the authorization token.
7. Ship: ciphertext envelope, nonce, `sender_public_key` (ephemeral), `recipient_salt`,
   `key_provider_type="passphrase"`.

### 6.3 Receiving (recipient's browser)
1. Metadata probe returns `sender_public_key`, `recipient_salt`, `key_provider_type`.
2. Derive the keypair from passphrase + `recipient_salt` (same `deriveX25519KeyPair`, kept
   byte-for-byte identical between `snipto.js` and `sniptoid.js` via `// SYNC:` markers).
3. **Validate `sender_public_key`** — the server-supplied one. This is not paranoia: a
   hostile sender can deliver a small-order or twist point and recover a long-lived private
   key from the resulting `key_hash` (a Lim-style attack with an offline oracle). §8.
4. ECDH → same HKDF labels (the recipient's own pubkey is re-derived, so the binding works
   symmetrically) → decrypt.

## 7. The `key_hash` — why the authorization token is memory-hard

A naive design stores `SHA-256(password)` server-side: one unsalted SHA-256 per guess,
trivially deduplicated across snippets, rainbow-table-able. Snipto instead derives
`key_hash` from the **same Argon2id master** as the ciphertext, via a third HKDF label
(`"key-hash"`) — or, for Snipto ID, as SHA-256 of the raw ECDH shared secret (already
full-entropy group-element bytes).

Consequence: an attacker who exfiltrates the database pays the **identical** 64 MB/guess
Argon2id cost to crack `key_hash` as to crack the payload. The authorization check adds no
cheaper oracle than the encryption itself, and per-snipto nonce-derived salts defeat
cross-snipto dedup.

Online, the check is `hash_equals()` (timing-safe), runs **before** the payload is returned
or the view counter decrements — a wrong secret never consumes the snippet — and every
attempt burns a one-time `reveal_token` (SHA-256-hashed at rest, 15-min TTL, overwritten by
each new probe), forcing attackers back through the tiered per-IP throttle
(20/min → 10/2min → 5/5min → 12 h lockout).

## 8. X25519 public-key validation (the part nobody else does)

`x25519PeerKeyValid()` (`resources/js/snipto.js`) implements RFC 9496's cofactor-less check:
a point is accepted iff `[order]P = identity`, computed with a pure-BigInt Montgomery ladder
over the u-coordinate. This rejects:

- the `u = 0` and `u = ±1` degenerate cases (`u = 0, 1, p−1` — small-order points). Byte-wise
  these are `0x00` × 32, `0x01` followed by 31 `0x00` (little-endian), and `p−1`. "All-one"
  (`0xff` × 32) is *not* one of them: the high-bit mask decodes it to `2^255 − 1 ≡ 18 (mod p)`.
- every twist-curve point (the Lim attack class),
- anything outside the prime-order subgroup.

It runs on **both** ECDH paths (typed recipient ID *and* server-supplied sender key), always
**before** any `crypto_scalarmult`, and the ladder is followed by the RFC 7748 §6.1
all-zero-shared-secret check. The implementation is pinned by RFC 7748 test vectors and
cross-checked against libsodium in `tests/js/x25519-validation.test.mjs` (CI); the reject
path is exercised end-to-end by the trap-key test in `tests/e2e/sniptoid.spec.js`.

## 9. Mode 0 — Plaintext (E2EE disabled)

No crypto at all: the payload is stored as-is and the slug is the only access control —
which is why the server enforces slug ≥ 8 chars for this mode (a 3-word diceware slug is
~31–38 bits) and the UI warns loudly. Included for non-sensitive convenience sharing
(e.g. a long URL to text somewhere). It is not a security mode and the threat model says so.

## 10. Protocol summary (wire view)

```
Create:   POST /api/snipto
            { slug, protection_type, payload(b64 envelope), nonce(24 hex),
              key_hash(64 hex), [sender_public_key, recipient_salt, key_provider_type] }
Probe:    GET  /api/snipto/{slug}
            → { protection_type, reveal_token, nonce | bootstrap fields }   # never the payload
Reveal:   POST /api/snipto/{slug}/reveal
            { reveal_token, key_hash } → payload (once) | 403 | 404
```

- Slugs: `[A-Za-z0-9_-]+`, ≥8 (Plaintext) / ≥4 (encrypted), reserved-slug denylist.
- Payload cap: 10 MB. Views: forced to 1 (single-use). Expiry: 1 h default; 1 h/1 d/1 w for
  Password and Snipto ID modes. Expired rows purged every 15 min.
- All three endpoints tiered-throttled per real client IP.

## 11. Threat-model cross-references

- What the server can/can't see, and known limitations: [docs/THREAT_MODEL.md](THREAT_MODEL.md)
- Vulnerability disclosure: [SECURITY.md](../SECURITY.md)

## 12. Prior art and reading

- RFC 7748 (X25519), RFC 9496 (X25519 validation), RFC 5869 (HKDF)
- OWASP Argon2 parameter cheat sheet (the strong tier we pin)
- "lim: breaking X25519 in the wild" — the attack class §8 defends against
- libsodium `crypto_pwhash` Argon2id docs

*Corrections welcome — an error in this document that matches an error in the code is a bug
report with extra steps.*
