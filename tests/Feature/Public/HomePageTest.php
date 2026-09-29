<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_offers_google_login_only(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Login dengan Google');
        $response->assertSee('href="'.route('login').'"', false);
        $response->assertDontSee('Buka dasbor');
        $response->assertDontSee('type="password"', false);
        $response->assertDontSee('type="email"', false);
        $response->assertDontSee('name="password"', false);
        $response->assertDontSee('name="email"', false);
    }

    public function test_authenticated_home_offers_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('home'));

        $response->assertOk();
        $response->assertSee('Buka dasbor');
        $response->assertSee('href="'.route('dashboard').'"', false);
        $response->assertDontSee('Login dengan Google');
    }
}
