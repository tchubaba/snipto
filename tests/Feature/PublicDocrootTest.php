<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * public/ is the web docroot: everything under it is fetchable by URL, and any
 * .php among them is executed rather than served. Laravel ships exactly one, and
 * it only works at the top of the docroot — its requires are relative to
 * __DIR__, so a copy one level down resolves public/vendor/autoload.php and
 * fatals. A fatal still runs, and neither image loads a php.ini, so
 * display_errors is on and the response body carries absolute paths and a call
 * stack at HTTP 200.
 *
 * Not hypothetical: public/js/index.php sat in the repo for 13 months, reachable
 * at /js/index.php through both nginx and `php artisan serve`, and baked into
 * the published image because .dockerignore excludes public/build but not
 * public/js/build.
 */
class PublicDocrootTest extends TestCase
{
    #[Test]
    public function the_docroot_holds_exactly_one_php_entry_point(): void
    {
        $entryPoint = realpath(base_path('public/index.php'));
        $this->assertNotFalse($entryPoint, 'public/index.php is the front controller; it must exist.');

        // Finder follows symlinks, and public/storage points out of the docroot
        // on checkouts that have run `link:storage` — skip links so the scan
        // stays inside public/.
        $found = [];
        $scan  = Finder::create()
            ->in(base_path('public'))
            ->files()
            ->name('/\.ph(p[3457]?|ar|tml)$/i')
            ->filter(static fn (\SplFileInfo $file): bool => ! $file->isLink());

        foreach ($scan as $file) {
            $found[] = realpath($file->getPathname());
        }

        $this->assertSame(
            [$entryPoint],
            $found,
            'The docroot must contain exactly one PHP file: public/index.php. '
            . 'Anything else in there is reachable by URL and executed by the web server.',
        );
    }

    #[Test]
    public function that_entry_point_is_the_framework_front_controller(): void
    {
        // The count above is only meaningful if the one PHP file is genuinely the
        // front controller rather than some script that happens to sit at the top
        // level of the docroot.
        $index = (string) file_get_contents(base_path('public/index.php'));

        $this->assertStringContainsString("require_once __DIR__.'/../bootstrap/app.php'", $index);
        $this->assertStringContainsString('handleRequest(Request::capture())', $index);
    }
}
