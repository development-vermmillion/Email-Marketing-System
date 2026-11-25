<?php

namespace App\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';       // waiting to be sent (or waiting for a retry)
    case Sending = 'sending';       // claimed by a worker
    case Sent = 'sent';             // accepted by the SMTP relay - NOT proof of delivery
    case Delivered = 'delivered';   // confirmed by a provider webhook
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Skipped = 'skipped';       // unsubscribed / suppressed / deleted / campaign cancelled

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Sending => 'primary',
            self::Sent => 'info',
            self::Delivered => 'success',
            self::Failed, self::Bounced => 'danger',
            self::Complained => 'warning',
            self::Skipped => 'dark',
        };
    }

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::Pending, self::Sending];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
