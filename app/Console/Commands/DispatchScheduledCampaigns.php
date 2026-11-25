<?php

namespace App\Console\Commands;

use App\Services\CampaignService;
use Illuminate\Console\Command;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-scheduled';

    protected $description = 'Start scheduled campaigns whose send time has arrived';

    public function handle(CampaignService $campaigns): int
    {
        $started = $campaigns->dispatchDue();
        $this->info("Started {$started} scheduled campaign(s).");

        return self::SUCCESS;
    }
}
