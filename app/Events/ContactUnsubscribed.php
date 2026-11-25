<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ContactUnsubscribed
{
    use Dispatchable;

    public function __construct(
        public string $email,
        public ?int $campaignId = null,
        public ?int $recipientId = null,
        public string $source = 'unsubscribe_link',
    ) {}
}
