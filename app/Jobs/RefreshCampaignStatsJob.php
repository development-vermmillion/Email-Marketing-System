<?php

namespace App\Jobs;

use App\Services\CampaignStatsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Coalesces bursts of webhook events into at most one recompute per campaign every few seconds. */
class RefreshCampaignStatsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 10;

    public function __construct(public int $campaignId)
    {
        $this->onQueue(config('mailing.queues.default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(CampaignStatsService $stats): void
    {
        $stats->refresh($this->campaignId);
    }
}
