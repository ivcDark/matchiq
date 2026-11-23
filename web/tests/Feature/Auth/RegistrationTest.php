<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('matches.index', absolute: false));
    }

    public function test_registration_requires_matching_passwords(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'Nick',
            'email' => 'nick@example.com',
            'password' => 'password',
            'password_confirmation' => 'different',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect('/register')
            ->assertSessionHasErrors('password');
    }

    public function test_registration_requires_unique_nickname(): void
    {
        User::factory()->create(['name' => 'TakenNick']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'TakenNick',
            'email' => 'other@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect('/register')
            ->assertSessionHasErrors('name');
    }
}
