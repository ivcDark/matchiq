<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_renders_for_guest(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('About'));
    }
}
