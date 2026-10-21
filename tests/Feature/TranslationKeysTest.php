<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the blade -> lang/*.json contract: every literal __('...') key used
 * in a Blade view (including the window.i18n maps that feed the JS t() helper)
 * must exist in every non-default locale file. Without this, a reworded or
 * newly added string silently renders English in localized UIs — the exact
 * drift that left 7 strings untranslated before this test existed.
 */
class TranslationKeysTest extends TestCase
{
    /** @return array<string,string> english key => first blade file seen */
    private function bladeTranslationKeys(): array
    {
        $keys = [];
        $it   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'))
        );
        $files = [];
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $files[] = $f->getPathname();
            }
        }
        foreach ($files as $file) {
            $src = file_get_contents($file);
            if (preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s', $src, $m)) {
                foreach ($m[1] as $raw) {
                    // PHP single-quoted semantics: only \' and \\ are escapes.
                    $key = preg_replace_callback(
                        '/\\\\(.)/',
                        static fn (array $c): string => ($c[1] === '\'' || $c[1] === '\\') ? $c[1] : $c[0],
                        $raw
                    );
                    $keys[$key] ??= basename($file);
                }
            }
        }
        return $keys;
    }

    #[Test]
    public function every_blade_translation_key_exists_in_every_locale(): void
    {
        $keys = $this->bladeTranslationKeys();
        $this->assertNotEmpty($keys, 'Blade key extraction regressed — check the regex.');

        foreach (array_keys(config('app.supported_locales')) as $locale) {
            if ($locale === config('app.locale')) {
                continue; // English keys ARE the source strings.
            }
            $path = base_path("lang/{$locale}.json");
            $this->assertFileExists($path);
            $translations = json_decode(file_get_contents($path), true);
            $this->assertIsArray($translations, "lang/{$locale}.json is not valid JSON.");
            $translations = (array) $translations;

            $missing = [];
            foreach (array_keys($keys) as $key) {
                if ( ! array_key_exists($key, $translations)) {
                    $missing[] = $key . '  (in ' . $keys[$key] . ')';
                }
            }
            $this->assertSame(
                [],
                $missing,
                "Missing translations in lang/{$locale}.json:\n" . implode("\n", $missing)
            );
        }
    }
}
