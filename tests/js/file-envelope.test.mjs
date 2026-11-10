#!/usr/bin/env node
/**
 * Unit test for the file-envelope framing helpers (buildFileEnvelope /
 * parseFileEnvelope in resources/js/snipto.js), part of the file-sharing
 * (50 MB) feature.
 *
 * Run with plain node (no test runner needed):
 *     node tests/js/file-envelope.test.mjs
 * CI runs it in the e2e job right after `npm ci`, next to the x25519 test.
 *
 * Like the x25519 test, the functions under test are extracted from the SHIPPED
 * source file — not a copy — by slicing between the exact marker lines
 *   // FILE-ENVELOPE-BEGIN ... // FILE-ENVELOPE-END
 * so this test fails if the framing constants or the rejection semantics drift.
 * If the markers are missing (helpers not landed yet) it exits 1 with a clear
 * message rather than crashing.
 *
 * Contract pinned here:
 *   buildFileEnvelope(name, mime, bytes) -> Uint8Array
 *     [2-byte big-endian headerLen][header JSON UTF-8 {n,m,s}][file bytes]
 *     throws 'Invalid file envelope header' if header length is 0 or > 0xFFFF.
 *   parseFileEnvelope(decryptedBytes) -> {name, mime, bytes}
 *     throws 'File envelope too short'          if input < 3 bytes
 *     throws 'Malformed file envelope header'   if headerLen === 0 or the
 *                                                header region overruns the
 *                                                buffer (or header bytes are
 *                                                not decodable/parsable)
 *     throws 'File envelope metadata mismatch'  unless n and m are strings and
 *                                                s === length - 2 - headerLen
 */

import { readFileSync } from 'fs';

const sniptoSrc = readFileSync(
    new URL('../../resources/js/snipto.js', import.meta.url),
    'utf8'
);

const BEGIN = '// FILE-ENVELOPE-BEGIN';
const END = '// FILE-ENVELOPE-END';
const begin = sniptoSrc.indexOf(BEGIN);
const end = sniptoSrc.indexOf(END);
if (begin === -1 || end === -1 || end < begin) {
    console.error('FAIL  could not locate the file-envelope block in resources/js/snipto.js — '
        + 'the marker lines // FILE-ENVELOPE-BEGIN and // FILE-ENVELOPE-END are missing; '
        + 'run this test only after the envelope helpers land.');
    process.exit(1);
}

// Evaluate the exact shipped region and pull out the symbols under test.
let helpers;
try {
    helpers = new Function(
        sniptoSrc.slice(begin, end + END.length)
        // The slice ends on the END marker comment line — the return must
        // start on a fresh line or it would be swallowed by that comment.
        + '\n; return { buildFileEnvelope, parseFileEnvelope };'
    )();
} catch (err) {
    console.error('FAIL  the file-envelope block did not evaluate standalone '
        + '(buildFileEnvelope/parseFileEnvelope symbols missing or the block depends on '
        + 'browser globals at top level): ' + err);
    process.exit(1);
}
const { buildFileEnvelope, parseFileEnvelope } = helpers;

let fails = 0;
const check = (name, cond) => { console.log((cond ? 'PASS' : 'FAIL') + '  ' + name); if (!cond) fails++; };

// Expect fn to throw; expected === null accepts any throw (used where the
// contract error may legitimately escape as a TextDecoder/JSON SyntaxError).
const expectThrow = (name, fn, expected) => {
    let thrown = null;
    try {
        fn();
    } catch (err) {
        thrown = err instanceof Error ? err.message : String(err);
    }
    if (thrown === null) {
        console.log('FAIL  ' + name + ' — no throw');
        fails++;
    } else if (expected === null || thrown.includes(expected)) {
        console.log('PASS  ' + name + ' (threw: ' + thrown + ')');
    } else {
        console.log('FAIL  ' + name + ' — threw wrong error: ' + thrown);
        fails++;
    }
};

const bytesEqual = (a, b) =>
    a.length === b.length && a.every((v, i) => v === b[i]);

// Mixed, adversarial file bytes: NULs, high bytes, CRLF — nothing may be
// escaped, truncated, or reinterpreted by the framing.
const fileBytes = Uint8Array.from([70, 0, 255, 128, 1, 2, 3, 250, 10, 13, 0, 255, 191, 120, 55, 44]);

const roundTrip = (label, name, mime, bytes) => {
    let parsed = null;
    let err = null;
    try {
        parsed = parseFileEnvelope(buildFileEnvelope(name, mime, bytes));
    } catch (e) {
        err = e instanceof Error ? e.message : String(e);
    }
    if (parsed === null) {
        console.log('FAIL  round-trip ' + label + ' — threw: ' + err);
        fails += 3;
        return null;
    }
    check('round-trip ' + label + ' name preserved', parsed.name === name);
    check('round-trip ' + label + ' mime preserved', parsed.mime === mime);
    check('round-trip ' + label + ' bytes identical', bytesEqual(parsed.bytes, bytes));
    return parsed;
};

// 1. Round-trip: ASCII name, unicode name, zero-length file (s:0 accepted).
roundTrip('ascii', 'report.pdf', 'application/pdf', fileBytes);
roundTrip('unicode', '报告 ñ € —.pdf', 'text/plain; charset=utf-8', fileBytes);
const zeroParsed = roundTrip('zero-length', 'empty.bin', 'application/octet-stream', new Uint8Array(0));
if (zeroParsed !== null) {
    check('round-trip zero-length accepted with bytes.length === 0', zeroParsed.bytes.length === 0);
}

// 2. Framing exactness, byte-level, on a known envelope.
const built = buildFileEnvelope('report.pdf', 'application/pdf', fileBytes);
check('build returns Uint8Array', built instanceof Uint8Array);
const headerLen = (built[0] << 8) | built[1];
check('framing: header length fits one byte (out[0] === 0)', built[0] === 0);
check('framing: out[1] === headerLen (big-endian)', built[1] === headerLen && headerLen > 0);
check('framing: out.length === 2 + headerLen + fileBytes.length', built.length === 2 + headerLen + fileBytes.length);
check('framing: tail after 2+headerLen is exactly the file bytes', bytesEqual(built.subarray(2 + headerLen), fileBytes));
// The header region must be exactly headerLen UTF-8 BYTES (not JS chars) and
// carry the contract fields — a char-count framing breaks here on the
// unicode-name envelope below.
let headerText = null;
try {
    headerText = new TextDecoder('utf-8', { fatal: true }).decode(built.subarray(2, 2 + headerLen));
} catch (e) {
    console.log('FAIL  framing: header region is not ' + headerLen + ' valid UTF-8 bytes: ' + e);
    fails++;
}
if (headerText !== null) {
    check('framing: header region round-trips to exactly headerLen bytes',
        new TextEncoder().encode(headerText).length === headerLen);
    let hdr = null;
    try { hdr = JSON.parse(headerText); } catch (e) { /* reported by the check below */ }
    check('framing: header JSON carries n/m/s correctly',
        hdr !== null && hdr.n === 'report.pdf' && hdr.m === 'application/pdf' && hdr.s === fileBytes.length);
}
// Byte-level framing again on the unicode envelope: if headerLen were a char
// count, this slice would land mid-codepoint and the fatal decode would throw.
const uniBuilt = buildFileEnvelope('报告 ñ € —.pdf', 'text/plain', fileBytes);
const uniHeaderLen = (uniBuilt[0] << 8) | uniBuilt[1];
check('framing (unicode): out.length === 2 + headerLen + fileBytes.length', uniBuilt.length === 2 + uniHeaderLen + fileBytes.length);
let uniHeaderOk = false;
try {
    const t = new TextDecoder('utf-8', { fatal: true }).decode(uniBuilt.subarray(2, 2 + uniHeaderLen));
    const h = JSON.parse(t);
    uniHeaderOk = h.n === '报告 ñ € —.pdf' && h.m === 'text/plain' && h.s === fileBytes.length
        && new TextEncoder().encode(t).length === uniHeaderLen;
} catch (e) { /* reported by the check below */ }
check('framing (unicode): headerLen counts UTF-8 bytes, header decodes+parses', uniHeaderOk);

// 3. Rejection: valid envelope with out[1] = 0xFF inflating headerLen past
//    the end of the buffer.
const overrun = Uint8Array.from(built);
overrun[1] = 0xFF;
check('setup: inflated headerLen (255) really exceeds buffer length', 2 + 255 > overrun.length);
expectThrow('reject: headerLen overruns buffer ("Malformed file envelope header")',
    () => parseFileEnvelope(overrun), 'Malformed file envelope header');

// 4. Rejection: metadata mismatch — bump the header's s by 1, buffer length
//    unchanged, header stays valid JSON.
const sMatch = /"s"\s*:\s*(\d+)/.exec(headerText ?? '');
if (sMatch === null) {
    console.log('FAIL  setup: could not locate "s":N in the built header to tamper');
    fails++;
} else {
    const bumpedText = headerText.replace(/"s"\s*:\s*(\d+)/, (m, d) =>
        m.slice(0, m.length - d.length) + String(Number(d) + 1));
    const bumpedBytes = new TextEncoder().encode(bumpedText);
    if (bumpedBytes.length !== headerLen) {
        console.log('FAIL  setup: tampering s changed header byte length (' + bumpedBytes.length
            + ' != ' + headerLen + ')');
        fails++;
    } else {
        const tampered = new Uint8Array(2 + headerLen + fileBytes.length);
        tampered[0] = built[0];
        tampered[1] = built[1];
        tampered.set(bumpedBytes, 2);
        tampered.set(fileBytes, 2 + headerLen);
        expectThrow('reject: s bumped by 1, same buffer length ("File envelope metadata mismatch")',
            () => parseFileEnvelope(tampered), 'File envelope metadata mismatch');
    }
}

// 5. Rejection: header region replaced with invalid UTF-8 (0xFF 0xFE ...) while
//    the length field is kept. TextDecoder(fatal) throws first; the contract
//    error or a raw decode SyntaxError both count as "throws at all".
const badUtf8 = Uint8Array.from(built);
for (let i = 0; i < headerLen; i++) badUtf8[2 + i] = (i % 2 === 0) ? 0xFF : 0xFE;
check('setup: header length field kept intact in non-UTF8 case', badUtf8[0] === 0 && badUtf8[1] === headerLen);
expectThrow('reject: non-UTF8 header bytes throw',
    () => parseFileEnvelope(badUtf8), null);

// 6. Rejection: short and empty inputs.
expectThrow('reject: 2-byte input ("File envelope too short")',
    () => parseFileEnvelope(new Uint8Array([0, 47])), 'File envelope too short');
expectThrow('reject: empty input ("File envelope too short")',
    () => parseFileEnvelope(new Uint8Array(0)), 'File envelope too short');

// 7. Rejection: headerLen === 0 is malformed even with a plausible buffer.
expectThrow('reject: headerLen 0 ("Malformed file envelope header")',
    () => parseFileEnvelope(Uint8Array.from([0, 0, 123, 34, 110, 34, 58, 49, 125])), 'Malformed file envelope header');

// 8. buildFileEnvelope rejects headers that cannot fit the 2-byte length field.
const hugeName = 'a'.repeat(70000);
expectThrow('build: header JSON > 0xFFFF bytes ("Invalid file envelope header")',
    () => buildFileEnvelope(hugeName, 'application/pdf', fileBytes), 'Invalid file envelope header');

// 9. Rejection: JSON header parses to null ("null") or primitive instead of object.
const nullHeaderBytes = new TextEncoder().encode('null');
const nullEnvelope = new Uint8Array(2 + nullHeaderBytes.length);
nullEnvelope[0] = 0;
nullEnvelope[1] = nullHeaderBytes.length;
nullEnvelope.set(nullHeaderBytes, 2);
expectThrow('reject: JSON header "null" ("File envelope metadata mismatch")',
    () => parseFileEnvelope(nullEnvelope), 'File envelope metadata mismatch');

process.exit(fails ? 1 : 0);
