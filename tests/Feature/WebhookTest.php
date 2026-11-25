<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailEvent;
use App\Models\SmtpSetting;
use App\Models\SuppressionEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function post(string $body, ?string $secret = 'whsec_test_secret')
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $headers['HTTP_X_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/webhooks/generic', [], [], [], $headers, $body);
    }

    public function test_requests_without_valid_signature_are_rejected(): void
    {
        SmtpSetting::factory()->create(['webhook_secret' => 'whsec_test_secret']);
        $body = json_encode(['event' => 'delivered', 'email' => 'a@example.com']);

        $this->post($body, null)->assertUnauthorized();
        $this->post($body, 'wrong-secret')->assertUnauthorized();
        $this->assertSame(0, EmailEvent::count());
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->postJson('/webhooks/nope', [])->assertNotFound();
    }

    public function test_delivered_event_updates_recipient_and_replays_are_ignored(): void
    {
        SmtpSetting::factory()->create(['webhook_secret' => 'whsec_test_secret']);
        $recipient = CampaignRecipient::factory()->create(['status' => 'sent']);
        $body = json_encode(['events' => [['id' => 'evt-1', 'event' => 'delivered', 'recipient_token' => $recipient->tracking_token]]]);

        $this->post($body)->assertSuccessful();
        $this->post($body)->assertSuccessful(); // provider retry

        $this->assertSame('delivered', $recipient->fresh()->status->value);
        $this->assertSame(1, EmailEvent::where('event_type', 'delivered')->count());
    }

    public function test_hard_bounce_suppresses_the_address(): void
    {
        SmtpSetting::factory()->create(['webhook_secret' => 'whsec_test_secret']);
        $recipient = CampaignRecipient::factory()->create(['status' => 'sent']);
        $body = json_encode(['id' => 'evt-2', 'event' => 'bounce', 'bounce_type' => 'hard', 'reason' => 'mailbox unknown', 'recipient_token' => $recipient->tracking_token]);

        $this->post($body)->assertSuccessful();

        $this->assertSame('bounced', $recipient->fresh()->status->value);
        $this->assertTrue(SuppressionEntry::where('email', $recipient->email)->where('reason', 'bounced')->exists());
    }

    public function test_soft_bounce_does_not_suppress(): void
    {
        SmtpSetting::factory()->create(['webhook_secret' => 'whsec_test_secret']);
        $recipient = CampaignRecipient::factory()->create(['status' => 'sent']);
        $body = json_encode(['id' => 'evt-3', 'event' => 'bounce', 'bounce_type' => 'soft', 'recipient_token' => $recipient->tracking_token]);

        $this->post($body)->assertSuccessful();

        $this->assertFalse(SuppressionEntry::where('email', $recipient->email)->exists());
    }
}
