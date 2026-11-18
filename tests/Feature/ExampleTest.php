<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_page_goes_to_the_dashboard_or_login(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
