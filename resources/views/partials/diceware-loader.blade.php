{{--
    Lazy loader for the per-locale diceware wordlist.

    Defines window.generateDicewarePassphrase as an async function that loads the
    correct wordlist file on first call. Only the wordlist for the current locale
    is fetched; other languages' wordlists never touch the network. Include this
    partial in `@section('header-js')` on pages that offer the "Generate for me"
    affordance (currently /sniptoid and the Snipto-creation form's password mode).

    Also defines window.generateDicewareSlug for the "Create one for me" slug
    suggestion; it samples only the slug-safe subset of the list (see below).
--}}
@php
    // English uses the 1,296-word EFF Short list (~10.34 bits/word) → 6 words ≈ 62 bits.
    // The other locales use larger 7,776-word lists (~12.92 bits/word) → 5 words ≈ 64.6 bits,
    // so we keep similar entropy while shortening passphrases that have longer average words.
    $localeWordlists = [
        'en'    => ['file' => 'wordlist_en',    'words' => 6],
        'pt_BR' => ['file' => 'wordlist_pt-br', 'words' => 5],
        'es'    => ['file' => 'wordlist_es',    'words' => 5],
        'fr'    => ['file' => 'wordlist_fr',    'words' => 5],
        'de'    => ['file' => 'wordlist_de',    'words' => 5],
    ];
    $localeCfg     = $localeWordlists[app()->getLocale()] ?? $localeWordlists['en'];
    $wordlistPath  = "build/js/wordlists/{$localeCfg['file']}.js";
    $wordlistUrl   = asset($wordlistPath)
        . '?v=' . (file_exists(public_path($wordlistPath)) ? filemtime(public_path($wordlistPath)) : '0');
    $wordCount     = $localeCfg['words'];

    // Word count for suggested slugs ("Create one for me"): 3 hyphen-joined
    // words in every locale. Entropy after the slug-safe filter (see
    // generateDicewareSlug below): ~31 bits on the 1,296-word EFF short list
    // (1,295 slug-safe words) and ~37-38 bits on the 7,776-word locale lists
    // (5,550-6,463 slug-safe words). Three words always clear the 8-char
    // plaintext slug floor (shortest slug-safe word is 3 chars -> min slug 11),
    // and a hyphenated multi-word slug can never collide with the reserved-slug
    // denylist (all single words).
    // Pinned server-side by tests/Feature/DicewareSlugWordlistTest.php — keep
    // the word counts in lockstep with that test.
    $slugWordCount = 3;
@endphp
<script @cspNonce>
    (function () {
        const url = @json($wordlistUrl);
        const defaultWordCount = @json($wordCount);
        const slugWordCount = @json($slugWordCount);
        let loadPromise = null;

        // Required because the page enforces require-trusted-types-for 'script'.
        // Assigning a plain string to script.src is blocked; we must wrap the URL
        // in a TrustedScriptURL produced by a registered policy. The policy only
        // accepts the exact wordlist URL computed by the server.
        let scriptUrlPolicy = null;
        if (window.trustedTypes && window.trustedTypes.createPolicy) {
            scriptUrlPolicy = window.trustedTypes.createPolicy('snipto-wordlist', {
                createScriptURL: (input) => {
                    if (input !== url) throw new Error('Refused unexpected wordlist URL');
                    return input;
                },
            });
        }

        function loadWordlist() {
            if (window.SNIPTO_WORDLIST) return Promise.resolve();
            if (loadPromise) return loadPromise;
            loadPromise = new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = scriptUrlPolicy ? scriptUrlPolicy.createScriptURL(url) : url;
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('Failed to load diceware wordlist'));
                document.head.appendChild(s);
            });
            return loadPromise;
        }

        // Uniformly sample `count` words from `list` with rejection sampling
        // (no modulo bias).
        function pickWords(list, count) {
            const n = list.length;
            const max = Math.floor(0x10000 / n) * n;
            const out = [];
            const buf = new Uint16Array(1);
            while (out.length < count) {
                crypto.getRandomValues(buf);
                if (buf[0] < max) {
                    out.push(list[buf[0] % n]);
                }
            }
            return out;
        }

        // Generate a diceware-style passphrase. Accented words are fine here —
        // a passphrase never travels through the slug route charset.
        window.generateDicewarePassphrase = async function (wordCount) {
            await loadWordlist();
            return pickWords(window.SNIPTO_WORDLIST, wordCount ?? defaultWordCount).join('-');
        };

        // Suggest a memorable snipto slug ("Create one for me"). Slugs must match
        // the slug route charset ([A-Za-z0-9_-]+ in routes/web.php) and '-' is the
        // word separator, so sample only pure a-z words: accented words (present in
        // every non-English list, e.g. pt_BR "fósseis") and words containing a
        // hyphen (the EFF list's "yo-yo") are filtered out. Stripping accents
        // instead would collide (fósseis/fosseis duplicates). The filtered subset
        // still carries >= ~37 bits at the locale-tuned word count above.
        let slugWords = null;
        window.generateDicewareSlug = async function () {
            await loadWordlist();
            slugWords ??= window.SNIPTO_WORDLIST.filter(w => /^[a-z]+$/.test(w));
            return pickWords(slugWords, slugWordCount).join('-');
        };
    })();
</script>
