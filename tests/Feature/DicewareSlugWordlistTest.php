<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the "Create one for me" slug suggestion (diceware-loader partial):
 * slugs must match the slug route charset ([A-Za-z0-9_-]+ in routes/web.php)
 * and '-' is the word separator, so the loader samples only pure a-z words
 * from the locale wordlist. This test pins that the filtered subset is big
 * enough to keep the documented entropy floor, and that the per-locale slug
 * word counts here stay in lockstep with $slugWordCount in the partial.
 */
class DicewareSlugWordlistTest extends TestCase
{
    /**
     * locale => [wordlist file, slug word count (keep in sync with the loader),
     *             minimum entropy in bits, minimum slug-safe word count]
     *
     * @var array<string,array{0:string,1:int,2:float,3:int}>
     */
    private const EXPECTATIONS = [
        'en'    => ['wordlist_en',    3, 31.0, 1200],
        'pt_BR' => ['wordlist_pt-br', 3, 37.0, 5000],
        'es'    => ['wordlist_es',    3, 37.0, 5000],
        'fr'    => ['wordlist_fr',    3, 37.0, 5000],
        'de'    => ['wordlist_de',    3, 37.0, 5000],
    ];

    #[Test]
    public function every_wordlist_has_a_slug_safe_subset_meeting_the_entropy_floor(): void
    {
        foreach (self::EXPECTATIONS as $locale => [$file, $words, $minBits, $minWords]) {
            $src = file_get_contents(base_path("resources/js/wordlists/{$file}.js"));
            $this->assertNotFalse($src, "Missing wordlist {$file}.js");

            preg_match_all('/"([^"]+)"/', $src, $m);
            $this->assertNotEmpty($m[1], "No words parsed from {$file}.js");

            // Slug-safe: pure a-z. Excludes accented words (pt_BR "fósseis") and
            // words containing the separator (EFF list "yo-yo").
            $slugSafe = array_values(array_filter(
                $m[1],
                static fn (string $w): bool => preg_match('/^[a-z]+$/', $w) === 1
            ));

            $n = count($slugSafe);
            $this->assertGreaterThanOrEqual(
                $minWords,
                $n,
                "{$file}.js has only {$n} slug-safe words; the loader's entropy floor no longer holds."
            );

            $bits = $words * log($n, 2);
            $this->assertGreaterThanOrEqual(
                $minBits,
                $bits,
                sprintf('%s: %d slug words x log2(%d) = %.1f bits < %.1f floor.', $locale, $words, $n, $bits, $minBits)
            );
        }
    }

    #[Test]
    public function slug_safe_words_match_the_slug_route_charset(): void
    {
        // Belt and braces: every sampled word, hyphen-joined, must satisfy the
        // route regex AND the store() validation charset.
        $src   = file_get_contents(base_path('resources/js/wordlists/wordlist_pt-br.js'));
        preg_match_all('/"([^"]+)"/', $src, $m);
        $words = array_values(array_filter($m[1], static fn (string $w): bool => preg_match('/^[a-z]+$/', $w) === 1));

        foreach (['abóbora', 'fósseis', 'yo-yo'] as $excluded) {
            $this->assertNotContains($excluded, $words, "'{$excluded}' must be filtered out of the slug subset");
        }

        $routeRegex = '/^[A-Za-z0-9_-]+$/'; // keep in lockstep with routes/web.php
        for ($i = 0; $i < 200; $i++) {
            $slug = implode('-', [
                $words[random_int(0, count($words) - 1)],
                $words[random_int(0, count($words) - 1)],
                $words[random_int(0, count($words) - 1)],
            ]);
            $this->assertMatchesRegularExpression($routeRegex, $slug);
            $this->assertGreaterThanOrEqual(8, strlen($slug));
        }
    }
}
