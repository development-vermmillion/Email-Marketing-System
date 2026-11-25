<?php

namespace App\Listeners;

use App\Enums\SuppressionReason;
use App\Events\EmailBounced;
use App\Services\SuppressionService;

class SuppressBouncedContact
{
    public function __construct(private SuppressionService $suppression) {}

    public function handle(EmailBounced $event): void
    {
        // Soft bounces (mailbox full, temporary DNS...) are retried by the provider and must not suppress.
        if ($event->bounceType !== 'hard') {
            return;
        }

        $this->suppression->add($event->email, SuppressionReason::Bounced, 'hard_bounce', $event->campaignId, $event->reason);
    }
}
