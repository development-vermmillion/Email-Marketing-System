<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\SuppressionReason;
use App\Jobs\RefreshCampaignStatsJob;
use App\Models\Audience;
use App\Models\CampaignRecipient;
use App\Models\SuppressionEntry;
use DomainException;
use Illuminate\Support\Facades\DB;

class AudienceService
{
    public function __construct(private SuppressionService $suppression, private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>  $groupIds
     */
    public function create(array $data, array $groupIds = [], ?string $ip = null): Audience
    {
        $email = mb_strtolower(trim((string) $data['email']));
        $existing = Audience::withTrashed()->where('email', $email)->first();

        if ($existing && ! $existing->trashed()) {
            throw new DomainException('A contact with this email address already exists.');
        }

        $attributes = [
            'name' => $this->clean($data['name'] ?? null, 255),
            'email' => $email,
            'phone' => $this->clean($data['phone'] ?? null, 50),
            'company' => $this->clean($data['company'] ?? null, 255),
            'status' => $data['status'] ?? 'active',
            'source' => $data['source'] ?? 'manual',
            'consent_at' => now(),
            'consent_ip' => $ip,
        ];

        if ($existing) {
            // Re-adding a deleted contact restores it. Its subscription status is kept, so a previously
            // unsubscribed person does NOT silently become subscribable again.
            $existing->restore();
            $existing->update($attributes);
            $audience = $existing;
        } else {
            $audience = Audience::create($attributes + ['subscription_status' => SubscriptionStatus::Subscribed->value]);

            if (isset($this->suppression->suppressedAmong([$email])[$email])) {
                $reason = SuppressionEntry::where('email', $email)->value('reason');
                $audience->update(['subscription_status' => $reason === 'manual' ? 'unsubscribed' : $reason]);
            }
        }

        $audience->groups()->syncWithoutDetaching($groupIds);
        $this->activity->log('audience', 'created', "Contact {$audience->email} added", null, ['audience_id' => $audience->id]);

        return $audience;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int>|null  $groupIds  null = leave group membership untouched
     */
    public function update(Audience $audience, array $data, ?array $groupIds = null): Audience
    {
        $requested = $data['subscription_status'] ?? $audience->subscription_status;

        if ($requested !== $audience->subscription_status) {
            if ($requested === SubscriptionStatus::Unsubscribed->value) {
                $this->suppression->add($audience->email, SuppressionReason::Unsubscribed, 'admin');
            } elseif ($requested === SubscriptionStatus::Subscribed->value && $this->suppression->isSuppressed($audience->email)) {
                throw new DomainException('This address is on the suppression list. Remove the manual suppression first, or the contact must re-subscribe themselves.');
            }
        }

        $audience->update([
            'name' => $this->clean($data['name'] ?? $audience->name, 255),
            'email' => $data['email'] ?? $audience->email,
            'phone' => $this->clean($data['phone'] ?? $audience->phone, 50),
            'company' => $this->clean($data['company'] ?? $audience->company, 255),
            'status' => $data['status'] ?? $audience->status,
        ]);

        if ($groupIds !== null) {
            $audience->groups()->sync($groupIds);
        }

        $this->activity->log('audience', 'updated', "Contact {$audience->email} updated", null, ['audience_id' => $audience->id]);

        return $audience;
    }

    /**
     * Deleting removes contacts from active use immediately: they are soft-deleted (history stays intact),
     * and every still-pending campaign recipient for them is skipped right now. The sender also re-checks
     * at send time, so there is no window in which a deleted contact can be emailed.
     *
     * @param  array<int>  $ids
     */
    public function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return 0;
        }

        $affectedCampaigns = [];

        $count = DB::transaction(function () use ($ids, &$affectedCampaigns) {
            $pending = CampaignRecipient::query()->whereIn('audience_id', $ids)->where('status', 'pending');
            $affectedCampaigns = (clone $pending)->distinct()->pluck('campaign_id')->all();
            $pending->update(['status' => 'skipped', 'failure_reason' => 'Contact was deleted']);

            // Mass soft-delete: a single UPDATE, even for thousands of ids.
            return Audience::query()->whereIn('id', $ids)->delete();
        });

        foreach ($affectedCampaigns as $campaignId) {
            RefreshCampaignStatsJob::dispatch($campaignId);
        }

        $this->activity->log('audience', 'deleted', "{$count} contact(s) deleted", null, ['count' => $count]);

        return $count;
    }

    private function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        // Strip control characters (also blocks header-injection attempts via names) and cap the length.
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
