<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Complained = 'complained';

    public function badge(): string
    {
        return match ($this) {
            self::Subscribed => 'success',
            self::Unsubscribed => 'secondary',
            self::Bounced => 'danger',
            self::Complained => 'warning',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
