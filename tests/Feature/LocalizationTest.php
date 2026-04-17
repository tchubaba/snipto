<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.supported_locales' => [
                'en' => ['name' => 'English', 'flag' => 'us'],
                'fr' => ['name' => 'Français', 'flag' => 'fr'],
                'es' => ['name' => 'Español', 'flag' => 'es'],
            ],
        ]);
    }

    #[Test]
    public function it_defaults_to_english_locale()
    {
        $this->get('/');
        $this->assertEquals('en', App::getLocale());
    }

    #[Test]
    public function it_can_change_locale_via_route()
    {
        $response = $this->post('/locale', [
            'locale' => 'fr',
        ]);

        $response->assertRedirect();
        $response->assertCookie('user_locale', 'fr');
    }

    #[Test]
    public function it_sets_locale_from_cookie()
    {
        $this->withCookie('user_locale', 'es')->get('/');
        $this->assertEquals('es', App::getLocale());
    }

    #[Test]
    public function it_falls_back_to_default_for_unsupported_locale()
    {
        $this->withCookie('user_locale', 'unsupported')->get('/');
        $this->assertEquals('en', App::getLocale());
    }
}
