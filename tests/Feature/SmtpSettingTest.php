<?php

namespace Tests\Feature;

use App\Models\SmtpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmtpSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted_at_rest_and_hidden_in_the_page(): void
    {
        $this->actingAs(User::factory()->create());
        $smtp = SmtpSetting::factory()->create(['username' => 'plain-user', 'password' => 'plain-secret-pw']);

        $raw = DB::table('smtp_settings')->where('id', $smtp->id)->first();
        $this->assertStringNotContainsString('plain-secret-pw', $raw->password);
        $this->assertStringNotContainsString('plain-user', $raw->username);
        $this->assertArrayNotHasKey('password', $smtp->toArray());

        $this->get('/smtp')->assertOk()->assertDontSee('plain-secret-pw')->assertDontSee('plain-user');
    }

    public function test_blank_credentials_on_update_keep_the_stored_values(): void
    {
        $this->actingAs(User::factory()->create());
        $smtp = SmtpSetting::factory()->create(['username' => 'u1', 'password' => 'p1']);

        $this->put("/smtp/{$smtp->id}", [
            'name' => 'Renamed', 'provider' => 'generic', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => '', 'from_name' => 'A', 'from_email' => 'a@example.com',
        ])->assertRedirect();

        $smtp->refresh();
        $this->assertSame('Renamed', $smtp->name);
        $this->assertSame('u1', $smtp->username);
        $this->assertSame('p1', $smtp->password);
    }
}
