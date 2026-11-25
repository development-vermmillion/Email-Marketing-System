<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Services\CampaignService;
use App\Services\CampaignStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecoverStaleSends extends Command
{
    protected $signature = 'campaigns:recover-stale';

    protected $description = 'Close out recipients stuck in "sending" and re-queue campaigns whose batch jobs were lost';

    public function handle(CampaignService $campaigns, CampaignStatsService $stats): int
    {
        $cutoff = now()->subMinutes((int) config('mailing.stale_sending_minutes'));

        // A worker died after claiming a recipient. We cannot know whether the relay accepted the message, so we
        // deliberately do NOT resend it (that could email someone twice): it is marked failed with a clear reason.
        $affected = CampaignRecipient::query()
            ->where('status', RecipientStatus::Sending->value)
            ->where('last_attempt_at', '<', $cutoff)
            ->pluck('campaign_id')->unique();

        $closed = CampaignRecipient::query()
            ->where('status', RecipientStatus::Sending->value)
            ->where('last_attempt_at', '<', $cutoff)
            ->update([
                'status' => RecipientStatus::Failed->value,
                'failure_type' => 'unknown',
                'failure_reason' => 'Outcome unknown: the worker stopped while sending. Not retried automatically to avoid sending a duplicate.',
            ]);

        // Pending recipients with no recent send activity: the queued batch jobs were probably lost (Redis flush/restart).
        // Re-dispatching is safe because every send is gated by the atomic claim.
        $requeued = 0;
        Campaign::query()->where('status', CampaignStatus::Sending->value)->each(function (Campaign $campaign) use ($campaigns, &$requeued) {
            $hasPending = CampaignRecipient::where('campaign_id', $campaign->id)->where('status', RecipientStatus::Pending->value)->exists();
            $recentActivity = EmailLog::where('campaign_id', $campaign->id)->where('created_at', '>=', now()->subMinutes(15))->exists();

            if ($hasPending && ! $recentActivity && Cache::add("campaign:{$campaign->id}:healed", 1, now()->addMinutes(30))) {
                $campaigns->dispatchBatches($campaign);
                $requeued++;
            }
        });

        foreach ($affected as $campaignId) {
            $stats->refresh($campaignId);

            if ($campaign = Campaign::find($campaignId)) {
                $campaigns->finalizeIfDone($campaign);
            }
        }

        $this->info("Closed {$closed} stale recipient(s); re-queued {$requeued} campaign(s).");

        return self::SUCCESS;
    }
}
