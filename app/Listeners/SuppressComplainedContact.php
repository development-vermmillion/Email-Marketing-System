<?php

namespace App\Listeners;

use App\Enums\SuppressionReason;
use App\Events\EmailComplained;
use App\Services\SuppressionService;

class SuppressComplainedContact
{
    public function __construct(private SuppressionService $suppression) {}

    public function handle(EmailComplained $event): void
    {
        $this->suppression->add($event->email, SuppressionReason::Complained, 'spam_complaint', $event->campaignId);
    }
}
