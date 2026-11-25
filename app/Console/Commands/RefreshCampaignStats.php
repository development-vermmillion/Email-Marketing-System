<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\CampaignService;
use App\Services\CampaignStatsService;
use Illuminate\Console\Command;

class RefreshCampaignStats extends Command
{
    protected $signature = 'campaigns:refresh-stats';

    protected $description = 'Recompute progress counters for running and recently finished campaigns (safety net for the live updates)';

    public function handle(CampaignStatsService $stats, CampaignService $campaigns): int
    {
        $n = 0;

        Campaign::query()
            ->where(fn ($q) => $q
                ->whereIn('status', [CampaignStatus::Queued->value, CampaignStatus::Sending->value, CampaignStatus::Paused->value])
                ->orWhere('completed_at', '>=', now()->subDay()))
            ->each(function (Campaign $campaign) use ($stats, $campaigns, &$n) {
                $stats->refresh($campaign);

                if ($campaign->status === CampaignStatus::Sending) {
                    $campaigns->finalizeIfDone($campaign);
                }

                $n++;
            });

        $this->info("Refreshed {$n} campaign(s).");

        return self::SUCCESS;
    }
}
