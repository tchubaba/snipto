#!/usr/bin/env python3
"""
Diceware wordlist generator for Snipto.

Pipeline:
    1. Download a frequency-ranked corpus for the target language.
    2. Filter (length, allowed character set, blacklist, prefix property).
    3. Trim to the target size, sort alphabetically.
    4. Emit a JS file matching the shape of resources/js/wordlist.js.

Usage:
    python3 generate.py pt-br
"""

from __future__ import annotations

import argparse
import re
import sys
import unicodedata
import urllib.request
from dataclasses import dataclass
from pathlib import Path

ROOT = Path(__file__).resolve().parent
CACHE_DIR = ROOT / ".cache"
REPO_ROOT = ROOT.parent.parent
OUTPUT_DIR = REPO_ROOT / "resources" / "js" / "wordlists"

TARGET_SIZE = 7776            # 6^5 — standard 5-dice diceware list
MIN_LEN = 4
MAX_LEN = 8
CANDIDATE_POOL = 25_000       # how many top-frequency words to consider before filtering


@dataclass
class LanguageConfig:
    code: str                 # snipto locale code, e.g. "pt-br"
    source_url: str           # URL of the frequency-ranked word list (one "word count" per line)
    allowed_chars: str        # regex character class, lowercase only
    description: str          # human-readable header for the JS output
    # Optional Hunspell dictionary for validating that a candidate is a real word in this
    # language. Filters proper nouns (capitalized in dic — Hunspell rejects their lowercase
    # form), foreign words, and other-variant spellings. Provide both .dic and .aff URLs.
    dict_dic_url: str | None = None
    dict_aff_url: str | None = None
    dict_attribution: str = ""
    # LDNOOBW per-language file name (e.g. "pt", "de"). When set, that profanity list
    # is auto-fetched and merged into the local blacklist_<code>.txt before filtering.
    ldnoobw_lang: str | None = None
    # Languages where common nouns are conventionally capitalized (notably German).
    # When True, dictionary validation accepts a candidate if either the lowercase form
    # OR the title-cased form is recognised. The output list stays lowercase.
    capitalize_lookup: bool = False


LANGUAGES: dict[str, LanguageConfig] = {
    "pt-br": LanguageConfig(
        code="pt-br",
        source_url="https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/pt_br/pt_br_50k.txt",
        # Brazilian Portuguese letters with diacritics: ã õ ç á é í ó ú â ê ô à
        # (no ü — dropped from PT-BR after the 1990 Acordo Ortográfico)
        allowed_chars=r"a-zãõçáéíóúâêôà",
        description="Brazilian Portuguese — frequency from OpenSubtitles 2018 (Hermit Dave, MIT); validated against LibreOffice VERO pt_BR Hunspell dictionary (Raimundo Moura, LGPL-3 / MPL).",
        dict_dic_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/pt_BR/pt_BR.dic",
        dict_aff_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/pt_BR/pt_BR.aff",
        dict_attribution="LibreOffice VERO pt_BR (LGPL-3 / MPL)",
        ldnoobw_lang="pt",
    ),
}

LANGUAGES["es"] = LanguageConfig(
    code="es",
    source_url="https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/es/es_50k.txt",
    # Spanish letters: a-z plus ñ and accented vowels á é í ó ú ü.
    allowed_chars=r"a-zñáéíóúü",
    description="Spanish — frequency from OpenSubtitles 2018 (Hermit Dave, MIT); validated against LibreOffice es_ES Hunspell dictionary (RLA-ES, GPL/LGPL/MPL multi-license).",
    dict_dic_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/es/es_ES.dic",
    dict_aff_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/es/es_ES.aff",
    dict_attribution="LibreOffice es_ES / RLA-ES (GPL/LGPL/MPL)",
    ldnoobw_lang="es",
)

LANGUAGES["fr"] = LanguageConfig(
    code="fr",
    source_url="https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/fr/fr_50k.txt",
    # French letters: a-z plus accented vowels and ligatures.
    allowed_chars=r"a-zàâäæçéèêëîïôœùûüÿ",
    description="French — frequency from OpenSubtitles 2018 (Hermit Dave, MIT); validated against LibreOffice fr Hunspell dictionary (Grammalecte / Dicollecte, MPL-2.0).",
    dict_dic_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/fr_FR/fr.dic",
    dict_aff_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/fr_FR/fr.aff",
    dict_attribution="LibreOffice fr / Dicollecte (MPL-2.0)",
    ldnoobw_lang="fr",
)

LANGUAGES["de"] = LanguageConfig(
    code="de",
    source_url="https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/de/de_50k.txt",
    # German letters: a-z plus umlauts ä ö ü and ß.
    allowed_chars=r"a-zäöüß",
    description="German — frequency from OpenSubtitles 2018 (Hermit Dave, MIT); validated against LibreOffice de_DE_frami Hunspell dictionary (igerman98, GPL/LGPL/OASIS multi-license).",
    dict_dic_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/de/de_DE_frami.dic",
    dict_aff_url="https://raw.githubusercontent.com/LibreOffice/dictionaries/master/de/de_DE_frami.aff",
    dict_attribution="LibreOffice de_DE_frami / igerman98 (GPL/LGPL/OASIS)",
    ldnoobw_lang="de",
    capitalize_lookup=True,
)

LDNOOBW_URL = "https://raw.githubusercontent.com/LDNOOBW/List-of-Dirty-Naughty-Obscene-and-Otherwise-Bad-Words/master/{lang}"

# Multilingual first-name database (Matthias Winkelmann), used at build time only to
# filter out personal names that leak through subtitle-derived frequency lists. ~45k
# unique names across European/Asian languages. GFDL — fine for build-time consumption,
# never redistributed.
FIRSTNAMES_URL = "https://raw.githubusercontent.com/MatthiasWinkelmann/firstname-database/master/firstnames.csv"


def fold(word: str) -> str:
    """NFD-decompose, drop combining marks, lowercase. Used for blacklist matching only."""
    nfd = unicodedata.normalize("NFD", word)
    return "".join(c for c in nfd if unicodedata.category(c) != "Mn").lower()


def fetch_source(cfg: LanguageConfig) -> Path:
    CACHE_DIR.mkdir(exist_ok=True)
    cache_file = CACHE_DIR / f"{cfg.code}.txt"
    if not cache_file.exists():
        print(f"Downloading {cfg.source_url} ...", file=sys.stderr)
        urllib.request.urlretrieve(cfg.source_url, cache_file)
    return cache_file


def load_dictionary(cfg: LanguageConfig):
    """Return a spylls Dictionary instance, or None if no dictionary is configured."""
    if not (cfg.dict_dic_url and cfg.dict_aff_url):
        return None
    try:
        from spylls.hunspell import Dictionary  # type: ignore
    except ImportError:
        print(
            "ERROR: spylls is required for dictionary validation. "
            "Install it in the venv: tools/wordlist-gen/.venv/bin/pip install spylls",
            file=sys.stderr,
        )
        sys.exit(1)
    dict_dir = CACHE_DIR / f"dict-{cfg.code}"
    dict_dir.mkdir(parents=True, exist_ok=True)
    dic_path = dict_dir / f"{cfg.code}.dic"
    aff_path = dict_dir / f"{cfg.code}.aff"
    if not dic_path.exists():
        print(f"Downloading {cfg.dict_dic_url} ...", file=sys.stderr)
        urllib.request.urlretrieve(cfg.dict_dic_url, dic_path)
    if not aff_path.exists():
        print(f"Downloading {cfg.dict_aff_url} ...", file=sys.stderr)
        urllib.request.urlretrieve(cfg.dict_aff_url, aff_path)
    return Dictionary.from_files(str(dict_dir / cfg.code))


def make_validator(d, capitalize_lookup: bool, blacklist: set[str]):
    """Return a callable(word) -> (accepted: bool, reject_reason: str | None).

    Validates two things in one pass for efficiency:
      1. word is in the dictionary (capitalize_lookup also tries title case)
      2. none of the word's possible Hunspell stems are in the blacklist
         — kills all conjugated/inflected forms of a blacklisted lemma
    """
    def stems(w: str):
        # Each form is either an AffixForm (single stem) or a CompoundForm (parts of
        # AffixForms, e.g. German "Krankenhaus" = Kranken + haus). We collect stems from
        # all parts so a compound containing a blacklisted lemma (e.g. "Hitlerstaat")
        # is rejected.
        out: set[str] = set()
        for form in d.lookuper.good_forms(w):
            parts = form.parts if hasattr(form, "parts") else [form]
            for p in parts:
                stem = getattr(p, "stem", None)
                if stem:
                    out.add(fold(stem))
        return out

    def check(w: str):
        in_dict = d.lookup(w)
        title = w[:1].upper() + w[1:]
        if not in_dict and capitalize_lookup:
            in_dict = d.lookup(title)
        if not in_dict:
            return (False, "dict")
        # Collect stems from both forms (for capitalize_lookup languages, the title form
        # is the canonical entry).
        candidate_stems = stems(w) | (stems(title) if capitalize_lookup else set())
        if candidate_stems & blacklist:
            return (False, "stem")
        return (True, None)

    return check


def fetch_firstnames() -> set[str]:
    """Download the multilingual first-name DB. Returns folded names. Cached."""
    CACHE_DIR.mkdir(exist_ok=True)
    cache_file = CACHE_DIR / "firstnames.csv"
    if not cache_file.exists():
        print(f"Downloading firstname database: {FIRSTNAMES_URL}", file=sys.stderr)
        urllib.request.urlretrieve(FIRSTNAMES_URL, cache_file)
    raw = cache_file.read_text(encoding="utf-8").replace("\r", "")
    out: set[str] = set()
    for i, line in enumerate(raw.splitlines()):
        if i == 0 or not line:
            continue
        # First column is the name (semicolon-separated CSV).
        name = line.split(";", 1)[0].strip()
        if name and "+" not in name:  # filter Pinyin tone-marked rows like "a+lian"
            out.add(fold(name))
    return out


def fetch_ldnoobw(lang: str) -> set[str]:
    """Download the LDNOOBW profanity list for `lang` (e.g. "pt", "de") and return folded entries."""
    CACHE_DIR.mkdir(exist_ok=True)
    cache_file = CACHE_DIR / f"ldnoobw_{lang}.txt"
    if not cache_file.exists():
        url = LDNOOBW_URL.format(lang=lang)
        print(f"Downloading LDNOOBW profanity list: {url}", file=sys.stderr)
        urllib.request.urlretrieve(url, cache_file)
    out: set[str] = set()
    for line in cache_file.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line:
            out.add(fold(line))
    return out


def load_blacklist(cfg: LanguageConfig) -> set[str]:
    out: set[str] = set()
    path = ROOT / f"blacklist_{cfg.code}.txt"
    if path.exists():
        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            out.add(fold(line))
    if cfg.ldnoobw_lang:
        out |= fetch_ldnoobw(cfg.ldnoobw_lang)
    out |= fetch_firstnames()
    return out


def load_candidates(corpus_path: Path, cfg: LanguageConfig, blacklist: set[str], validator) -> list[str]:
    """Return up to CANDIDATE_POOL words, ordered by descending frequency, after filtering.

    Filters: length, allowed character set, blacklist (literal + stem), Hunspell dict lookup.
    Hunspell is case-sensitive: proper nouns stored capitalized in the .dic are rejected
    when looked up in their lowercase form, which is exactly what we want.
    """
    pattern = re.compile(rf"^[{cfg.allowed_chars}]+$")
    seen: set[str] = set()
    out: list[str] = []
    rej_dict = 0
    rej_stem = 0
    with corpus_path.open(encoding="utf-8") as fh:
        for line in fh:
            parts = line.strip().split()
            if len(parts) != 2:
                continue
            word = parts[0].lower()
            if not (MIN_LEN <= len(word) <= MAX_LEN):
                continue
            if not pattern.match(word):
                continue
            if word in seen:
                continue
            if fold(word) in blacklist:
                continue
            if validator is not None:
                accepted, reason = validator(word)
                if not accepted:
                    if reason == "dict":
                        rej_dict += 1
                    elif reason == "stem":
                        rej_stem += 1
                    continue
            seen.add(word)
            out.append(word)
            if len(out) >= CANDIDATE_POOL:
                break
    if validator is not None:
        print(f"Rejected by dictionary: {rej_dict}, by stem-blacklist: {rej_stem}", file=sys.stderr)
    return out


def enforce_prefix_property(words: list[str], target: int) -> list[str]:
    """
    Greedy by frequency rank: accept word W only if no already-accepted word is a prefix of W
    AND W is not a prefix of any already-accepted word. Stops when `target` words are kept.

    The greedy-by-frequency choice means we prefer common words over rarer ones when there's
    a prefix conflict (e.g. between "casa" and "casamento" the more common one wins).
    """
    accepted: list[str] = []
    accepted_set: set[str] = set()
    # For O(1) prefix-of-existing checks, track all prefixes of accepted words:
    prefixes_of_accepted: set[str] = set()

    for w in words:
        # (a) is any existing word a prefix of w?
        is_extension = any(w.startswith(a) and w != a for a in accepted_set if len(a) < len(w))
        if is_extension:
            continue
        # (b) is w a prefix of any existing word?
        if w in prefixes_of_accepted:
            continue

        accepted.append(w)
        accepted_set.add(w)
        for i in range(1, len(w)):
            prefixes_of_accepted.add(w[:i])
        if len(accepted) >= target:
            break
    return accepted


def write_output(words: list[str], cfg: LanguageConfig) -> Path:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    out_path = OUTPUT_DIR / f"wordlist_{cfg.code}.js"
    bits_per_word = (len(words).bit_length() - 1)  # exact for power-of-two; floor otherwise
    # Real entropy:
    import math
    entropy = math.log2(len(words))
    header = (
        f"// {cfg.description}\n"
        f"// {len(words):,} words, ~{entropy:.2f} bits of entropy per word.\n"
        f"// 5 words ≈ {5 * entropy:.1f} bits, 6 words ≈ {6 * entropy:.1f} bits.\n"
        f"// Generated by tools/wordlist-gen/generate.py — do not edit by hand.\n"
    )
    body_lines = []
    # 12 words per line, matching the visual style of resources/js/wordlist.js
    chunk = 12
    for i in range(0, len(words), chunk):
        row = ", ".join(f'"{w}"' for w in words[i:i + chunk])
        body_lines.append(f"    {row},")
    body = "\n".join(body_lines)
    contents = f'{header}\nwindow.SNIPTO_WORDLIST = [\n{body}\n];\n'
    out_path.write_text(contents, encoding="utf-8")
    return out_path


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("language", choices=sorted(LANGUAGES.keys()))
    args = parser.parse_args()

    cfg = LANGUAGES[args.language]
    blacklist = load_blacklist(cfg)
    print(f"Blacklist: {len(blacklist)} entries (folded, incl. LDNOOBW)", file=sys.stderr)

    corpus_path = fetch_source(cfg)
    d = load_dictionary(cfg)
    validator = make_validator(d, cfg.capitalize_lookup, blacklist) if d else None
    candidates = load_candidates(corpus_path, cfg, blacklist, validator)
    print(f"Candidates after length+charset+blacklist filter: {len(candidates)}", file=sys.stderr)

    final = enforce_prefix_property(candidates, TARGET_SIZE)
    print(f"After prefix-property filter: {len(final)}", file=sys.stderr)

    if len(final) < TARGET_SIZE:
        print(
            f"ERROR: only {len(final)} words after filtering, need {TARGET_SIZE}. "
            f"Increase CANDIDATE_POOL or relax filters.",
            file=sys.stderr,
        )
        return 1

    final.sort()
    out_path = write_output(final, cfg)
    print(f"Wrote {len(final)} words to {out_path}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
