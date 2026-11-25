<?php

namespace App\Enums;

enum SuppressionReason: string
{
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
