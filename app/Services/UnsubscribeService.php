<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\SuppressionReason;
use App\Events\ContactUnsubscribed;
use App\Jobs\RefreshCampaignStatsJob;
use App\Models\Audience;
use App\Models\CampaignRecipient;
use App\Models\SuppressionEntry;
use Illuminate\Support\Facades\DB;

class UnsubscribeService
{
    public function __construct(private EmailEventRecorder $recorder) {}

    public function isUnsubscribed(CampaignRecipient $r): bool
    {
        return SuppressionEntry::where('email', $r->email)->exists();
    }

    /** Idempotent: unsubscribing twice (link + one-click header, mail-client prefetch) is harmless. */
    public function unsubscribe(CampaignRecipient $r, string $source = 'unsubscribe_link', ?string $ip = null): void
    {
        DB::transaction(function () use ($r, $source, $ip) {
            CampaignRecipient::whereKey($r->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);

            $this->recorder->record('unsub|'.$r->id, [
                'campaign_id' => $r->campaign_id,
                'campaign_recipient_id' => $r->id,
                'provider' => 'tracking',
                'event_type' => 'unsubscribed',
                'email' => $r->email,
                'ip' => $ip,
                'reason' => $source,
                'occurred_at' => now(),
            ]);

            // Listener writes the suppression row and flips the contact's subscription status.
            event(new ContactUnsubscribed($r->email, $r->campaign_id, $r->id, $source));
        });

        RefreshCampaignStatsJob::dispatch($r->campaign_id);
    }

    /**
     * Lets a person who unsubscribed (by link) change their mind. Bounce/complaint/manual suppressions
     * can never be lifted this way.
     */
    public function resubscribe(CampaignRecipient $r, ?string $ip = null): bool
    {
        $entry = SuppressionEntry::where('email', $r->email)->first();

        if (! $entry || $entry->reason !== SuppressionReason::Unsubscribed) {
            return false;
        }

        DB::transaction(function () use ($entry, $r, $ip) {
            $entry->delete();

            Audience::withTrashed()->where('email', $r->email)->update([
                'subscription_status' => SubscriptionStatus::Subscribed->value,
                'unsubscribed_at' => null,
                'consent_at' => now(),
                'consent_ip' => $ip,
            ]);
        });

        return true;
    }
}
