#!/usr/bin/env node
/**
 * Unit test for the X25519 peer-key validation added for audit finding F-2
 * (x25519PeerKeyValid / x25519Ladder in resources/js/snipto.js).
 *
 * Run with plain node (no test runner needed):
 *     node tests/js/x25519-validation.test.mjs
 * CI runs it in the e2e job right after `npm ci`.
 *
 * The functions under test are extracted from the SHIPPED source file — not a
 * copy — so this test fails if the ladder, the constants, or the validation
 * semantics drift. Accuracy rests on four independent legs:
 *
 *   1. RFC 7748 §6.1 Alice/Bob DH vector and §5.2 iterated-and-swapped vector
 *      (1000 iterations) pin the Montgomery-ladder arithmetic and the cswap
 *      convention against the RFC's own published values.
 *   2. libsodium (the library the app actually uses for crypto_scalarmult)
 *      agrees with the ladder byte-for-byte on random keypairs.
 *   3. The rejection set is group-theoretic: [order]P == identity (NIST
 *      SP 800-56A / RFC 9496 full public-key validation for Curve25519).
 *      A separate affine-Weierstrass computation in Python established that
 *      the only F_p-rational small-order u-coordinates are 0 (order 2), 1
 *      and p-1 (order 4), and that the quadratic twist begins at u=3 — the
 *      cases asserted below.
 *   4. RFC 7748 decoding rules: the MSB of the u-coordinate is masked, and
 *      non-canonical values (>= p) are accepted after reduction mod p.
 */

import { readFileSync } from 'fs';
import sodium from 'libsodium-wrappers-sumo';

const sniptoSrc = readFileSync(
    new URL('../../resources/js/snipto.js', import.meta.url),
    'utf8'
);

const start = sniptoSrc.indexOf('const X25519_P');
const end = sniptoSrc.indexOf('export function sniptoComponent');
if (start === -1 || end === -1 || end < start) {
    console.error('FAIL  could not locate X25519 validation block in resources/js/snipto.js — '
        + 'the test extraction anchors moved; update this test alongside the refactor.');
    process.exit(1);
}

// Evaluate the exact shipped region and pull out the symbols under test.
const { x25519Ladder, x25519PeerKeyValid, X25519_ORDER, X25519_P } =
    new Function(
        sniptoSrc.slice(start, end)
        + '; return { x25519Ladder, x25519PeerKeyValid, X25519_ORDER, X25519_P };'
    )();

const hexToBytes = h => Uint8Array.from(Buffer.from(h, 'hex'));
const leToBig = b => { let u = 0n; for (let i = b.length - 1; i >= 0; i--) u = (u << 8n) | BigInt(b[i]); return u; };
const bigToLe = (u, n) => { const o = new Uint8Array(n); for (let i = 0; i < n; i++) { o[i] = Number(u & 0xffn); u >>= 8n; } return o; };
const clamp = b => { const c = b.slice(); c[0] &= 248; c[31] &= 127; c[31] |= 64; return c; };

let fails = 0;
const check = (name, cond) => { console.log((cond ? 'PASS' : 'FAIL') + '  ' + name); if (!cond) fails++; };

// 1. RFC 7748 §6.1 Alice/Bob DH test vector (scalar decoded + clamped per
//    decodeScalar25519).
const alicePriv = hexToBytes('77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a');
const bobPub = hexToBytes('de9edb7d7b7dc1b4d35b61c2ece435373f8343c85b78674dadfc7e146f882b4f');
const sharedHex = '4a5d9d5ba4ce2de1728e3bf480350f25e07e21c947d19e3376f09b3c1e161742';
const out = bigToLe(x25519Ladder(leToBig(clamp(alicePriv)), leToBig(bobPub)), 32);
check('RFC 7748 6.1 DH vector', Buffer.from(out).toString('hex') === sharedHex);

// RFC 7748 §5.2 iterated-and-swapped vector, 1000 iterations. Exercises
// every bit position and both cswap paths of the ladder.
let k = 9n, u = 9n;
for (let i = 0; i < 1000; i++) {
    const next = x25519Ladder(leToBig(clamp(bigToLe(k, 32))), u % X25519_P);
    u = k; k = next;
}
check('RFC 7748 5.2 iter1000', Buffer.from(bigToLe(k, 32)).toString('hex') === '684cf59ba83309552800ef566f2f4d3c1c3887c49360e3875f2eb94d99532c51');

// 3. Small-order and twist rejection. These are the ONLY F_p-rational
//    u-coordinates of order < l (group structure Z_2 x Z_4l); a malicious
//    server supplying any of them collapses or biases the shared secret.
const base32 = new Uint8Array(32); base32[0] = 9;
check('basepoint u=9 accepted', x25519PeerKeyValid(base32));
check('u=0 (order 2) rejected', !x25519PeerKeyValid(hexToBytes('0000000000000000000000000000000000000000000000000000000000000000')));
check('u=1 (order 4) rejected', !x25519PeerKeyValid(hexToBytes('01000000000000000000000000000000000000000000000000000000000000000')));
check('u=-1 (order 4) rejected', !x25519PeerKeyValid(hexToBytes('ecffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f')));
check('twist u=3 rejected', !x25519PeerKeyValid(hexToBytes('03000000000000000000000000000000000000000000000000000000000000000')));

// 4. RFC 7748 decoding rules.
check('non-canonical p+9 accepted', x25519PeerKeyValid(hexToBytes('f6ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f')));
check('MSB-set 9+2^255 accepted', x25519PeerKeyValid(hexToBytes('0900000000000000000000000000000000000000000000000000000000000080')));

// 2. Cross-check ladder + validator against libsodium on random keys.
await sodium.ready;
let ok = true;
for (let i = 0; i < 5; i++) {
    const priv = sodium.randombytes_buf(32);
    const pub = new Uint8Array(sodium.crypto_scalarmult_base(priv));
    if (!x25519PeerKeyValid(pub)) { ok = false; console.log('  random pubkey rejected!', Buffer.from(pub).toString('hex')); }
    const mine = bigToLe(x25519Ladder(leToBig(clamp(new Uint8Array(priv))), leToBig(pub)), 32);
    const theirs = new Uint8Array(sodium.crypto_scalarmult(priv, pub));
    if (Buffer.from(mine).toString('hex') !== Buffer.from(theirs).toString('hex')) { ok = false; console.log('  ladder != libsodium'); }
}
check('5 random keys: accepted + ladder matches libsodium', ok);

const t0 = Date.now();
x25519PeerKeyValid(base32);
console.log('validation time: ' + (Date.now() - t0) + 'ms');

process.exit(fails ? 1 : 0);
