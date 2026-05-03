# Wordlist generator

Builds locale-specific diceware wordlists for Snipto's passphrase generator from
public, permissively-licensed sources. Output is a JS file that mirrors the shape
of `resources/js/wordlist.js`.

## Setup

```bash
python3 -m venv tools/wordlist-gen/.venv
tools/wordlist-gen/.venv/bin/pip install spylls
```

`spylls` is a pure-Python Hunspell implementation used for dictionary validation
(filters proper nouns, foreign words, and other-variant spellings).

## Usage

```bash
tools/wordlist-gen/.venv/bin/python tools/wordlist-gen/generate.py pt-br
```

Writes `resources/js/wordlists/wordlist_<locale>.js`. Sources are downloaded once
into `tools/wordlist-gen/.cache/` (gitignored) and reused on subsequent runs.

## Pipeline

1. Download a frequency-ranked corpus for the target language.
2. Filter: length 4–8, allowed character set (lowercase + diacritics), blacklist.
3. Validate against the language's Hunspell dictionary if configured. Hunspell is
   case-sensitive — proper nouns stored capitalized in the `.dic` are rejected
   when looked up in lowercase, which is exactly what we want.
4. Stem-blacklist check: extract every possible Hunspell lemma for the candidate
   (including each part of a compound, e.g. German `Krankenhaus` → `Kranken` +
   `Haus`); reject if any stem appears in the folded blacklist. This kills all
   conjugated/inflected forms of a blacklisted lemma — blacklist `matar` once
   and `mataré`, `mataba`, `matarlo` are all caught automatically.
5. Greedy by frequency, enforce the prefix property (no word is a prefix of
   another), keep the top 7,776.
5. Sort alphabetically, emit JS.

7,776 = 6⁵ — standard 5-dice diceware size, ~12.92 bits/word. 5 words ≈ 64.6 bits,
matching the entropy of the existing 6-word EFF Short #1 English passphrase.

## Adding a language

Add an entry to `LANGUAGES` in `generate.py`. Required:

- `code` — Snipto locale code (e.g. `"es"`, `"fr"`, `"de"`).
- `source_url` — frequency-ranked word list, one `word count` per line.
- `allowed_chars` — regex character class for valid letters in this language.
- `description` — attribution line for the JS header.

Optional but strongly recommended:

- `dict_dic_url` / `dict_aff_url` — Hunspell dictionary for spell-check
  validation. Use language-specific dictionaries — German with a French dictionary
  will reject everything.
- `dict_attribution` — license/credit line for the dictionary.
- `ldnoobw_lang` — file name in the LDNOOBW repo (e.g. `"pt"`, `"de"`, `"es"`,
  `"fr"`). Auto-fetched and merged into the blacklist.
- `capitalize_lookup=True` — set for languages where common nouns are
  conventionally capitalized (German). Hunspell validation also tries the
  title-cased form, otherwise all common nouns get rejected.

Then create `blacklist_<code>.txt` with **language-specific** terms:
places (cities, countries, regions in this language's spelling), surnames,
brand names, slang/loaded terms not covered by LDNOOBW. Blacklist matching is diacritic-insensitive (NFD-folded), so plain ASCII
entries like `acao` will block `ação`.

## Sources used

### Per-language (frequency + spelling)

| Locale | Frequency corpus | License | Dictionary | License |
|--------|------------------|---------|------------|---------|
| pt-br  | [Hermit Dave FrequencyWords (OpenSubtitles 2018)](https://github.com/hermitdave/FrequencyWords) | MIT | [LibreOffice VERO pt_BR](https://github.com/LibreOffice/dictionaries/tree/master/pt_BR) | LGPL-3 / MPL |
| de     | [Hermit Dave FrequencyWords (OpenSubtitles 2018)](https://github.com/hermitdave/FrequencyWords) | MIT | [LibreOffice de_DE_frami (igerman98)](https://github.com/LibreOffice/dictionaries/tree/master/de) | GPL/LGPL/OASIS |
| es     | [Hermit Dave FrequencyWords (OpenSubtitles 2018)](https://github.com/hermitdave/FrequencyWords) | MIT | [LibreOffice es_ES (RLA-ES)](https://github.com/LibreOffice/dictionaries/tree/master/es) | GPL/LGPL/MPL |
| fr     | [Hermit Dave FrequencyWords (OpenSubtitles 2018)](https://github.com/hermitdave/FrequencyWords) | MIT | [LibreOffice fr (Dicollecte)](https://github.com/LibreOffice/dictionaries/tree/master/fr_FR) | MPL-2.0 |

### Global blacklists (auto-merged into every language)

| Source | Purpose | License |
|--------|---------|---------|
| [LDNOOBW per-language](https://github.com/LDNOOBW/List-of-Dirty-Naughty-Obscene-and-Otherwise-Bad-Words) | Profanity (60–80 entries per language) | MIT |
| [Matthias Winkelmann firstname-database](https://github.com/MatthiasWinkelmann/firstname-database) | ~45k personal names across 50+ countries — kills name leaks from subtitle corpora | GFDL 1.2+ |

We do not redistribute the dictionary `.dic` / `.aff` files — they are consumed
at build time only. The generated wordlist is a derivative work, but word
frequency counts and individual common words are not copyrightable; the thin
compilation copyright over the *generated selection* belongs to this project.
Attribution to upstream sources is preserved in the generated JS header.

## Reviewing output

After regenerating, eyeball samples:

```bash
grep -oE '"[^"]+"' resources/js/wordlists/wordlist_pt-br.js | tr -d '"' > /tmp/w.txt
shuf -n 100 /tmp/w.txt | sort | column
```

Add any unwanted entries to `blacklist_<code>.txt` and re-run.
