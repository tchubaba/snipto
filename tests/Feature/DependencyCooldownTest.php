<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Pins the three-layer supply-chain cooldown: Dependabot must not open a
 * version-update PR for a release younger than COOLDOWN_DAYS, Composer must
 * withhold such versions while resolving (composer update / require), and npm
 * must skip them while installing. Security updates are exempt from all three
 * by design, so a genuine fix still lands immediately.
 *
 * If one layer is loosened or dropped, this test fails: a cooldown only holds
 * when every layer that touches a new release applies it. The images are
 * pinned here too, because both gates are version-dependent: Composer only
 * enforces config.policy.cooldown from COOLDOWN_COMPOSER_VERSION on (older
 * releases print the setting and ignore it), and npm only honours
 * min-release-age from COOLDOWN_NPM_VERSION on.
 */
class DependencyCooldownTest extends TestCase
{
    private const COOLDOWN_DAYS = 3;

    /** First Composer release that enforces config.policy.cooldown. */
    private const COOLDOWN_COMPOSER_VERSION = '2.11.2';

    /** First npm CLI release that honours min-release-age. */
    private const COOLDOWN_NPM_VERSION = '11.10.0';

    /** Node major version whose bundled npm satisfies COOLDOWN_NPM_VERSION. */
    private const COOLDOWN_NODE_MAJOR = 24;

    private const DOCKERFILES = [
        'docker/Dockerfile',
        'docker/Dockerfile-fpm',
    ];

    private const ECOSYSTEMS = [
        'github-actions',
        'composer',
        'docker',
        'docker-compose',
        'npm',
    ];

    /**
     * File-name patterns of the dependabot-core file finders for the
     * ecosystems that abort the whole update run when the configured
     * directory holds no matching file (dependency_file_not_found).
     */
    private const SCANNED_MANIFESTS = [
        'docker'         => '/dockerfile|containerfile/i',
        'docker-compose' => '/(docker-)?compose(-[\w]+)?(\.[\w-]+)?\.ya?ml/i',
    ];

    #[Test]
    public function dependabot_cooldowns_every_monitored_ecosystem()
    {
        $config = Yaml::parseFile(base_path('.github/dependabot.yml'));

        $this->assertSame(2, $config['version']);

        $ecosystems = array_column($config['updates'], 'package-ecosystem');
        $this->assertEqualsCanonicalizing(self::ECOSYSTEMS, $ecosystems);

        foreach ($config['updates'] as $entry) {
            $ecosystem = $entry['package-ecosystem'];
            $this->assertArrayHasKey(
                'cooldown',
                $entry,
                "Dependabot cooldown missing for {$ecosystem}."
            );
            $this->assertGreaterThanOrEqual(
                self::COOLDOWN_DAYS,
                (int) ($entry['cooldown']['default-days'] ?? 0),
                "Dependabot cooldown for {$ecosystem} is shorter than " . self::COOLDOWN_DAYS . ' days.'
            );
        }
    }

    #[Test]
    public function dependabot_directories_hold_files_the_ecosystem_can_parse()
    {
        $config = Yaml::parseFile(base_path('.github/dependabot.yml'));

        foreach ($config['updates'] as $entry) {
            $ecosystem = $entry['package-ecosystem'];

            $directories = $entry['directories']
                ?? (isset($entry['directory']) ? [$entry['directory']] : []);
            $this->assertNotEmpty(
                $directories,
                "Dependabot entry for {$ecosystem} declares no directory."
            );

            foreach ($directories as $directory) {
                $absolute = rtrim(base_path(ltrim($directory, '/')), '/');
                $this->assertDirectoryExists(
                    $absolute,
                    "Dependabot entry for {$ecosystem} points at {$directory}, which does not exist."
                );

                if ( ! isset(self::SCANNED_MANIFESTS[$ecosystem])) {
                    continue;
                }

                $matches = array_values(array_filter(
                    scandir($absolute) ?: [],
                    fn (string $file): bool => (bool) preg_match(self::SCANNED_MANIFESTS[$ecosystem], $file)
                ));

                $this->assertNotEmpty(
                    $matches,
                    "Dependabot {$ecosystem} entry points at {$directory}, which holds no file that "
                        . 'ecosystem scans for; its update run aborts with dependency_file_not_found.'
                );
            }
        }
    }

    #[Test]
    public function composer_withholds_versions_younger_than_the_cooldown_period()
    {
        $manifest = json_decode(file_get_contents(base_path('composer.json')), true);
        $this->assertIsArray($manifest, 'composer.json is not valid JSON.');

        $period = $manifest['config']['policy']['cooldown']['period'] ?? null;
        $this->assertIsString(
            $period,
            'Composer cooldown period missing from composer.json (config.policy.cooldown.period).'
        );

        // Composer's duration grammar: "<number> <unit>", unit in
        // second|minute|hour|day|week (singular or plural).
        $this->assertSame(
            1,
            preg_match('/^(\d+) (second|minute|hour|day|week)s?$/', $period, $matches),
            "Composer cooldown period '{$period}' is not a Composer duration string."
        );

        $secondsPerDay = 86400;
        $factor        = [
            'second' => 1,
            'minute' => 60,
            'hour'   => 3600,
            'day'    => $secondsPerDay,
            'week'   => $secondsPerDay * 7,
        ][$matches[2]];

        $this->assertGreaterThanOrEqual(
            self::COOLDOWN_DAYS * $secondsPerDay,
            (int) $matches[1] * $factor,
            'Composer cooldown is shorter than ' . self::COOLDOWN_DAYS . ' days.'
        );
    }

    #[Test]
    public function npm_skips_versions_younger_than_the_cooldown_period()
    {
        $npmrc = base_path('.npmrc');
        $this->assertFileExists($npmrc, '.npmrc is missing: npm has no install-time cooldown without it.');

        $age = null;
        foreach (file($npmrc, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if (str_starts_with($line, ';') || str_starts_with($line, '#') || $line === '') {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === 'min-release-age') {
                $age = (int) $value;
            }
        }

        $this->assertNotNull(
            $age,
            'min-release-age is not set in .npmrc (npm CLI 11.10.0+; value is in days).'
        );
        $this->assertGreaterThanOrEqual(
            self::COOLDOWN_DAYS,
            $age,
            'npm min-release-age is shorter than ' . self::COOLDOWN_DAYS . ' days.'
        );
    }

    #[Test]
    public function images_ship_toolchains_new_enough_to_enforce_the_cooldowns()
    {
        foreach (self::DOCKERFILES as $dockerfile) {
            $path = base_path($dockerfile);
            $this->assertFileExists($path);
            $contents = file_get_contents($path);

            // Composer: the copied binary must be new enough to enforce the
            // cooldown policy.
            $this->assertSame(
                1,
                preg_match('#COPY --from=composer:(\d+\.\d+\.\d+)@#', $contents, $matches),
                "{$dockerfile} does not pin a tagged composer image."
            );
            $this->assertGreaterThanOrEqual(
                0,
                version_compare($matches[1], self::COOLDOWN_COMPOSER_VERSION),
                "{$dockerfile} ships composer {$matches[1]}, which ignores config.policy.cooldown."
            );

            // Node: the nodesource repository line decides the bundled npm.
            $this->assertSame(
                1,
                preg_match('#nodesource\.com/node_(\d+)\.x#', $contents, $matches),
                "{$dockerfile} does not pin a nodesource major version."
            );
            $this->assertGreaterThanOrEqual(
                self::COOLDOWN_NODE_MAJOR,
                (int) $matches[1],
                "{$dockerfile} ships Node {$matches[1]}, whose bundled npm predates min-release-age."
            );
        }
    }
}
