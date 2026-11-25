<?php

namespace App\Listeners;

use App\Events\CampaignStatusChanged;
use App\Services\ActivityLogger;

class LogCampaignStatusChange
{
    public function __construct(private ActivityLogger $logger) {}

    public function handle(CampaignStatusChanged $event): void
    {
        $from = $event->from?->value ?? 'new';
        $description = "Status changed from {$from} to {$event->to->value}".($event->note ? " ({$event->note})" : '');

        $this->logger->log('campaign', 'status.'.$event->to->value, $description, $event->campaign);
    }
}
