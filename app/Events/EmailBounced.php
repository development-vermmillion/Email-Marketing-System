<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class EmailBounced
{
    use Dispatchable;

    public function __construct(
        public string $email,
        public ?int $campaignId,
        public string $bounceType, // hard | soft
        public ?string $reason = null,
    ) {}
}
