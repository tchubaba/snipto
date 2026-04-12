<?php

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StaticPagesTest extends TestCase
{
    #[Test]
    public function it_loads_the_landing_page()
    {
        $response = $this->get('/');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_loads_the_faq_page()
    {
        $response = $this->get('/faq');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_loads_the_contact_page()
    {
        $response = $this->get('/contact');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_loads_the_terms_page()
    {
        $response = $this->get('/terms');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_loads_the_safety_page()
    {
        $response = $this->get('/safety');
        $response->assertStatus(200);
    }

    #[Test]
    public function it_returns_404_for_unknown_pages()
    {
        // This should hit the catch-all but return 200 because it loads the snipto view
        // But for really weird paths that don't match slug regex, it might 404
        $response = $this->get('/invalid/path/that/should/404');
        $response->assertStatus(404);
    }
}
