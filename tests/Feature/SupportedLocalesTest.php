<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pins the REAL supported-locale surface (LocalizationTest overrides
 * supported_locales with a fake set, so it never guards this config):
 * the exact locale set, their dropdown metadata, and translation-file
 * parity across all non-default locales.
 */
class SupportedLocalesTest extends TestCase
{
    #[Test]
    public function it_supports_exactly_the_documented_locale_set()
    {
        $this->assertEqualsCanonicalizing(
            ['en', 'pt_BR', 'es', 'fr', 'de'],
            array_keys(config('app.supported_locales')),
            'Supported locales changed — update lang/*.json, E2E specs, and docs together.'
        );
        $this->assertSame('en', config('app.locale'));
    }

    #[Test]
    public function each_locale_has_dropdown_metadata()
    {
        foreach (config('app.supported_locales') as $code => $meta) {
            $this->assertNotEmpty($meta['name'] ?? null, "Locale {$code} is missing a display name.");
            $this->assertNotEmpty($meta['flag'] ?? null, "Locale {$code} is missing a flag code.");
        }
    }

    #[Test]
    public function every_non_default_locale_has_a_translation_file()
    {
        foreach (array_keys(config('app.supported_locales')) as $code) {
            if ($code === config('app.locale')) {
                continue; // English: the __() keys ARE the source strings.
            }
            $path = base_path("lang/{$code}.json");
            $this->assertFileExists($path, "Missing translation file for locale {$code}.");

            $translations = json_decode(file_get_contents($path), true);
            $this->assertIsArray($translations, "lang/{$code}.json is not valid JSON.");
            $this->assertNotEmpty($translations);
        }
    }

    #[Test]
    public function translation_files_have_key_parity()
    {
        $keySets = [];
        foreach (array_keys(config('app.supported_locales')) as $code) {
            if ($code === config('app.locale')) {
                continue;
            }
            $path = base_path("lang/{$code}.json");
            if ( ! is_file($path)) {
                $this->fail("Missing translation file for locale {$code}.");
            }
            $keySets[$code] = array_keys(json_decode(file_get_contents($path), true));
        }

        $reference = array_shift($keySets);
        foreach ($keySets as $code => $keys) {
            $this->assertEqualsCanonicalizing(
                $reference,
                $keys,
                "Translation key drift in lang/{$code}.json — every locale must translate the same key set."
            );
        }
    }
}
