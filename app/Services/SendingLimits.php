<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\SmtpSetting;

/** Effective sending controls for a campaign. The strictest value wins so provider limits are always respected. */
final class SendingLimits
{
    public function __construct(
        public readonly int $perSecond,
        public readonly int $perMinute,
        public readonly ?int $dailyLimit,
        public readonly int $batchSize,
        public readonly int $maxAttempts,
        public readonly int $retryDelay,
    ) {}

    public static function for(Campaign $campaign, ?SmtpSetting $smtp): self
    {
        $min = function (?int ...$values): ?int {
            $values = array_filter($values, fn ($v) => $v !== null && $v > 0);

            return $values ? min($values) : null;
        };

        return new self(
            perSecond: $min($campaign->rate_per_second, $smtp?->rate_per_second) ?? (int) config('mailing.rate_per_second'),
            perMinute: $min($campaign->rate_per_minute, $smtp?->rate_per_minute) ?? (int) config('mailing.rate_per_minute'),
            dailyLimit: $min($campaign->daily_limit, $smtp?->daily_limit) ?? config('mailing.daily_limit'),
            batchSize: max(1, (int) ($campaign->batch_size ?: config('mailing.batch_size'))),
            maxAttempts: max(1, (int) ($campaign->max_attempts ?: config('mailing.max_attempts'))),
            retryDelay: max(30, (int) ($campaign->retry_delay ?: config('mailing.retry_delay'))),
        );
    }
}
