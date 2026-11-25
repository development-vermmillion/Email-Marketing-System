<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/', '/campaigns', '/audiences', '/templates', '/reports', '/smtp', '/suppression', '/settings'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_non_admin_users_cannot_access_the_panel(): void
    {
        $this->actingAs(User::factory()->nonAdmin()->create())->get('/campaigns')->assertForbidden();
    }

    public function test_admin_can_log_in_and_see_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk();
    }

    public function test_wrong_password_and_inactive_users_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors();
        $this->assertGuest();

        $inactive = User::factory()->inactive()->create(['password' => 'secret-pass-123']);
        $this->post('/login', ['email' => $inactive->email, 'password' => 'secret-pass-123'])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['/', '/campaigns', '/campaigns/create', '/audiences', '/imports/create', '/groups', '/templates', '/templates/create', '/reports', '/suppression', '/smtp', '/activity', '/settings'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }
}
