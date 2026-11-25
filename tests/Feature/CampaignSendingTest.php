<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\AudienceGroup;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailTemplate;
use App\Models\SmtpSetting;
use App\Services\CampaignSender;
use App\Services\CampaignService;
use App\Services\RecipientPreparer;
use App\Services\Smtp\SmtpMailerFactory;
use App\Services\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeMailerFactory;
use Tests\TestCase;

class CampaignSendingTest extends TestCase
{
    use RefreshDatabase;

    private SmtpSetting $smtp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SmtpMailerFactory::class, FakeMailerFactory::class);
        $this->smtp = SmtpSetting::factory()->create(['rate_per_second' => 1000, 'rate_per_minute' => 100000]);
    }

    private function sendingCampaign(array $groupIds = []): Campaign
    {
        return Campaign::factory()->create([
            'status' => CampaignStatus::Sending,
            'group_ids' => $groupIds,
            'smtp_setting_id' => $this->smtp->id,
            'email_template_id' => EmailTemplate::factory()->create(['html_content' => '<p>Hi {{name}}</p>'])->id,
        ]);
    }

    public function test_contact_in_two_groups_is_prepared_once(): void
    {
        $a = AudienceGroup::factory()->create();
        $b = AudienceGroup::factory()->create();
        $contact = Audience::factory()->create();
        $contact->groups()->attach([$a->id, $b->id]);
        Audience::factory()->create()->groups()->attach($a->id);

        $campaign = $this->sendingCampaign([$a->id, $b->id]);

        $this->assertSame(2, app(RecipientPreparer::class)->prepare($campaign));
        // Preparing again (job retry) must not create duplicates.
        app(RecipientPreparer::class)->prepare($campaign);
        $this->assertSame(2, CampaignRecipient::where('campaign_id', $campaign->id)->count());
    }

    public function test_suppressed_unsubscribed_and_inactive_contacts_are_not_prepared(): void
    {
        $ok = Audience::factory()->create();
        $suppressed = Audience::factory()->create();
        app(SuppressionService::class)->add($suppressed->email, SuppressionReason::Bounced);
        Audience::factory()->unsubscribed()->create();
        Audience::factory()->create(['status' => 'inactive']);

        $campaign = $this->sendingCampaign();
        app(RecipientPreparer::class)->prepare($campaign);

        $this->assertSame([$ok->email], CampaignRecipient::where('campaign_id', $campaign->id)->pluck('email')->all());
    }

    public function test_sending_the_same_batch_twice_sends_each_email_once(): void
    {
        Audience::factory()->count(3)->create();
        $campaign = $this->sendingCampaign();
        app(RecipientPreparer::class)->prepare($campaign);
        $ids = CampaignRecipient::where('campaign_id', $campaign->id)->pluck('id')->all();

        $sender = app(CampaignSender::class);
        $sender->sendBatch($campaign->id, $ids);
        $sender->sendBatch($campaign->id, $ids); // duplicate delivery of the same job

        $this->assertSame(3, CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'sent')->count());
        $this->assertSame(3, \App\Models\EmailLog::where('campaign_id', $campaign->id)->count());
    }

    public function test_batch_does_nothing_unless_campaign_is_sending(): void
    {
        Audience::factory()->create();
        $campaign = $this->sendingCampaign();
        app(RecipientPreparer::class)->prepare($campaign);
        $campaign->update(['status' => CampaignStatus::Paused]);

        app(CampaignSender::class)->sendBatch($campaign->id, CampaignRecipient::pluck('id')->all());

        $this->assertSame(0, CampaignRecipient::where('status', 'sent')->count());
        $this->assertSame(1, CampaignRecipient::where('status', 'pending')->count());
    }

    public function test_contact_suppressed_after_preparation_is_skipped_at_send_time(): void
    {
        $contact = Audience::factory()->create();
        $campaign = $this->sendingCampaign();
        app(RecipientPreparer::class)->prepare($campaign);

        app(SuppressionService::class)->add($contact->email, SuppressionReason::Unsubscribed);
        app(CampaignSender::class)->sendBatch($campaign->id, CampaignRecipient::pluck('id')->all());

        $this->assertSame('skipped', CampaignRecipient::first()->status->value);
    }

    public function test_contact_deleted_after_preparation_is_skipped(): void
    {
        $contact = Audience::factory()->create();
        $campaign = $this->sendingCampaign();
        app(RecipientPreparer::class)->prepare($campaign);
        $contact->delete();

        app(CampaignSender::class)->sendBatch($campaign->id, CampaignRecipient::pluck('id')->all());

        $this->assertSame('skipped', CampaignRecipient::first()->status->value);
    }

    public function test_state_machine_rejects_invalid_transitions(): void
    {
        $campaign = Campaign::factory()->status(CampaignStatus::Completed)->create();

        $this->assertFalse(app(CampaignService::class)->moveStatus($campaign, [CampaignStatus::Sending], CampaignStatus::Paused));
        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
    }

    public function test_expired_trash_is_purged_and_recent_trash_is_kept(): void
    {
        $old = Campaign::factory()->status(CampaignStatus::Trashed)->create(['deleted_at' => now()->subDays(8)]);
        $recent = Campaign::factory()->status(CampaignStatus::Trashed)->create(['deleted_at' => now()->subDays(2)]);
        CampaignRecipient::factory()->create(['campaign_id' => $old->id]);

        $this->assertSame(1, app(CampaignService::class)->purgeExpired());

        $this->assertNull(Campaign::withTrashed()->find($old->id));
        $this->assertNotNull(Campaign::withTrashed()->find($recent->id));
        $this->assertSame(0, CampaignRecipient::where('campaign_id', $old->id)->count());
    }
}
