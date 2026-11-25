<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\SuppressionEntry;
use App\Support\Email;
use DomainException;

class SuppressionService
{
    public function isSuppressed(string $email): bool
    {
        return SuppressionEntry::query()->where('email', Email::normalize($email))->exists();
    }

    /**
     * @param  iterable<string>  $emails
     * @return array<string, true> set keyed by lower-cased address
     */
    public function suppressedAmong(iterable $emails): array
    {
        $set = [];
        $normalized = [];

        foreach ($emails as $email) {
            $normalized[] = Email::normalize($email);
        }

        foreach (array_chunk(array_unique($normalized), 1000) as $chunk) {
            foreach (SuppressionEntry::query()->whereIn('email', $chunk)->pluck('email') as $found) {
                $set[$found] = true;
            }
        }

        return $set;
    }

    /**
     * Suppression rows are append-only from the application's point of view: they have no
     * foreign keys, so deleting or re-importing a contact never removes them.
     */
    public function add(
        string $email,
        SuppressionReason $reason,
        ?string $source = null,
        ?int $campaignId = null,
        ?string $note = null,
    ): SuppressionEntry {
        $email = Email::normalize($email);

        $entry = SuppressionEntry::firstOrCreate(
            ['email' => $email],
            ['reason' => $reason->value, 'source' => $source, 'campaign_id' => $campaignId, 'note' => $note],
        );

        // Reflect the reason that is actually on file (an existing "bounced" entry must not be re-labelled "unsubscribed").
        $this->syncAudience($email, $entry->reason);

        return $entry;
    }

    public function remove(SuppressionEntry $entry): void
    {
        if ($entry->reason !== SuppressionReason::Manual) {
            throw new DomainException(
                'Only manually added suppressions can be removed. Unsubscribes, bounces and complaints are permanent compliance records.'
            );
        }

        $entry->delete();
    }

    private function syncAudience(string $email, SuppressionReason $reason): void
    {
        $status = match ($reason) {
            SuppressionReason::Unsubscribed => SubscriptionStatus::Unsubscribed,
            SuppressionReason::Bounced => SubscriptionStatus::Bounced,
            SuppressionReason::Complained => SubscriptionStatus::Complained,
            SuppressionReason::Manual => null,
        };

        if ($status === null) {
            return;
        }

        Audience::withTrashed()->where('email', $email)->update([
            'subscription_status' => $status->value,
            'unsubscribed_at' => now(),
        ]);
    }
}
