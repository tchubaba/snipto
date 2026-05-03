{{--
    Lazy loader for the per-locale diceware wordlist.

    Defines window.generateDicewarePassphrase as an async function that loads the
    correct wordlist file on first call. Only the wordlist for the current locale
    is fetched; other languages' wordlists never touch the network. Include this
    partial in `@section('header-js')` on pages that offer the "Generate for me"
    affordance (currently /sniptoid and the Snipto-creation form's password mode).
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
@endphp
<script @cspNonce>
    (function () {
        const url = @json($wordlistUrl);
        const defaultWordCount = @json($wordCount);
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

        // Generate a diceware-style passphrase. Rejection sampling avoids modulo bias.
        window.generateDicewarePassphrase = async function (wordCount) {
            await loadWordlist();
            const list = window.SNIPTO_WORDLIST;
            const count = wordCount ?? defaultWordCount;
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
            return out.join('-');
        };
    })();
</script>
