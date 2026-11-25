<?php

namespace Tests\Feature;

use App\Models\Audience;
use App\Models\CampaignRecipient;
use App\Models\SuppressionEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(): array
    {
        $contact = Audience::factory()->create();
        $recipient = CampaignRecipient::factory()->create(['email' => $contact->email, 'audience_id' => $contact->id, 'status' => 'sent']);

        return [$contact, $recipient];
    }

    public function test_get_only_confirms_and_changes_nothing(): void
    {
        [$contact, $recipient] = $this->recipient();

        $this->get("/unsubscribe/{$recipient->tracking_token}")->assertOk();

        $this->assertSame('subscribed', $contact->fresh()->subscription_status);
        $this->assertSame(0, SuppressionEntry::count());
    }

    public function test_post_unsubscribes_and_suppresses(): void
    {
        [$contact, $recipient] = $this->recipient();

        $this->post("/unsubscribe/{$recipient->tracking_token}")->assertOk();

        $this->assertSame('unsubscribed', $contact->fresh()->subscription_status);
        $this->assertTrue(SuppressionEntry::where('email', $contact->email)->exists());
    }

    public function test_one_click_post_without_csrf_token_works(): void
    {
        [$contact, $recipient] = $this->recipient();

        $this->call('POST', "/unsubscribe/{$recipient->tracking_token}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertSame('unsubscribed', $contact->fresh()->subscription_status);
    }

    public function test_unknown_or_malformed_tokens_are_404(): void
    {
        $this->get('/unsubscribe/'.Str::random(64))->assertNotFound();
        $this->get('/unsubscribe/short')->assertNotFound();
    }

    public function test_bounced_address_cannot_resubscribe(): void
    {
        [$contact, $recipient] = $this->recipient();
        SuppressionEntry::create(['email' => $contact->email, 'reason' => 'bounced']);

        $this->post("/unsubscribe/{$recipient->tracking_token}/resubscribe")->assertOk()->assertSee("can't resubscribe", false);

        $this->assertTrue(SuppressionEntry::where('email', $contact->email)->exists());
    }
}
