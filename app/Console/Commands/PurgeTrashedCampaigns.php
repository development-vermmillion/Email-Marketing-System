<?php

namespace App\Console\Commands;

use App\Services\CampaignService;
use Illuminate\Console\Command;

class PurgeTrashedCampaigns extends Command
{
    protected $signature = 'campaigns:purge-trash';

    protected $description = 'Permanently delete campaigns that have been in the trash longer than the retention period (default 7 days)';

    public function handle(CampaignService $campaigns): int
    {
        $this->info('Permanently deleted '.$campaigns->purgeExpired().' campaign(s).');

        return self::SUCCESS;
    }
}
