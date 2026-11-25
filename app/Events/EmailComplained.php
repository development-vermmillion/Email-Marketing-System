<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class EmailComplained
{
    use Dispatchable;

    public function __construct(public string $email, public ?int $campaignId = null) {}
}
