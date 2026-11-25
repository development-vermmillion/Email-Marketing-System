<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Builds the recipient rows for a queued campaign and fans out the batch jobs. */
class StartCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public int $campaignId)
    {
        $this->onQueue(config('mailing.queues.default'));
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaign = Campaign::find($this->campaignId);

        if (! $campaign || $campaign->status !== CampaignStatus::Queued) {
            return;
        }

        $campaigns->begin($campaign);
    }

    public function failed(Throwable $e): void
    {
        Log::error('StartCampaignJob failed', ['campaign_id' => $this->campaignId, 'error' => $e->getMessage()]);

        if ($campaign = Campaign::find($this->campaignId)) {
            app(CampaignService::class)->moveStatus($campaign, [CampaignStatus::Queued, CampaignStatus::Sending], CampaignStatus::Failed, [], 'Could not prepare recipients');
        }
    }
}
