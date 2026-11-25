<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sending = 'sending';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Trashed = 'trashed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Scheduled => 'info',
            self::Queued, self::Sending => 'primary',
            self::Paused => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'dark',
            self::Trashed => 'light',
        };
    }

    /** Content and recipients may only change while the campaign has not started sending. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Sending, self::Paused], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
