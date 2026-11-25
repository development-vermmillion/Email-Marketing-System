<?php

namespace App\Listeners;

use App\Enums\SuppressionReason;
use App\Events\ContactUnsubscribed;
use App\Services\SuppressionService;

class SuppressUnsubscribedContact
{
    public function __construct(private SuppressionService $suppression) {}

    public function handle(ContactUnsubscribed $event): void
    {
        $this->suppression->add($event->email, SuppressionReason::Unsubscribed, $event->source, $event->campaignId);
    }
}
