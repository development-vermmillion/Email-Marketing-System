<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Audience;
use App\Models\AudienceGroup;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignCrudTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Diwali offer',
            'subject' => 'Hello {{first_name}}',
            'from_name' => 'Acme',
            'from_email' => 'news@example.com',
            'email_template_id' => EmailTemplate::factory()->create()->id,
            'action' => 'draft',
        ];
    }

    public function test_campaign_can_be_created_as_draft_and_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $group = AudienceGroup::factory()->create();

        $this->get('/campaigns/create')->assertOk();
        $this->post('/campaigns', $this->payload(['group_ids' => [$group->id]]))->assertRedirect();

        $campaign = Campaign::firstOrFail();
        $this->assertSame(CampaignStatus::Draft, $campaign->status);
        $this->assertSame([$group->id], $campaign->group_ids);

        $this->get("/campaigns/{$campaign->id}/edit")->assertOk()->assertSee('Diwali offer');

        // Un-ticking every group must clear the selection.
        $this->put("/campaigns/{$campaign->id}", $this->payload(['name' => 'Renamed', 'email_template_id' => $campaign->email_template_id]))->assertRedirect();
        $campaign->refresh();
        $this->assertSame('Renamed', $campaign->name);
        $this->assertSame([], $campaign->group_ids);
    }

    public function test_validation_errors_are_returned(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/campaigns', ['action' => 'draft'])->assertSessionHasErrors(['name', 'subject', 'from_email', 'email_template_id']);
    }

    public function test_schedule_requires_future_time_and_a_sendable_audience(): void
    {
        $this->actingAs(User::factory()->create());
        SmtpSetting::factory()->create();
        Audience::factory()->create();

        $this->post('/campaigns', $this->payload(['action' => 'schedule', 'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')]))->assertRedirect();

        $this->assertSame(CampaignStatus::Scheduled, Campaign::firstOrFail()->status);
    }

    public function test_started_campaign_cannot_be_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $campaign = Campaign::factory()->status(CampaignStatus::Sending)->create();

        $this->get("/campaigns/{$campaign->id}/edit")->assertRedirect("/campaigns/{$campaign->id}");
        $this->put("/campaigns/{$campaign->id}", $this->payload(['email_template_id' => $campaign->email_template_id]))->assertRedirect("/campaigns/{$campaign->id}");
        $this->assertNotSame('Diwali offer', $campaign->fresh()->name);
    }
}
