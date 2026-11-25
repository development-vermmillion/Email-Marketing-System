<?php

namespace App\Services;

use App\Enums\RecipientStatus;
use App\Events\ContactUnsubscribed;
use App\Events\EmailBounced;
use App\Events\EmailComplained;
use App\Jobs\RefreshCampaignStatsJob;
use App\Models\CampaignRecipient;
use App\Services\Webhooks\WebhookEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmailEventProcessor
{
    public function __construct(
        private EmailEventRecorder $recorder,
        private UnsubscribeService $unsubscribes,
    ) {}

    /** @return 'processed'|'duplicate'|'unmatched' */
    public function process(WebhookEvent $e): string
    {
        $recipient = $this->match($e);
        $occurred = $e->occurredAt ? Carbon::createFromTimestamp($e->occurredAt) : now();
        $email = mb_strtolower((string) ($e->email ?: $recipient?->email));

        // The dedupe key is deterministic per provider event, so a retried webhook is a no-op.
        $key = implode('|', [$e->provider, $e->type, $e->providerEventId ?: ($e->messageId.'|'.$email.'|'.$e->occurredAt)]);

        $stored = $this->recorder->record($key, [
            'campaign_id' => $recipient?->campaign_id ?? $e->campaignId,
            'campaign_recipient_id' => $recipient?->id,
            'provider' => $e->provider,
            'event_type' => $e->type,
            'provider_event_id' => $e->providerEventId,
            'email' => $email ?: null,
            'url' => $e->url,
            'bounce_type' => $e->bounceType,
            'reason' => $e->reason,
            'occurred_at' => $occurred,
            'raw_payload' => $e->raw,
        ]);

        if (! $stored) {
            return 'duplicate';
        }

        $campaignId = $recipient?->campaign_id;

        DB::transaction(function () use ($e, $recipient, $occurred, $email, $campaignId) {
            match ($e->type) {
                'delivered' => $recipient && $this->delivered($recipient, $occurred),
                'bounced' => $this->bounced($e, $recipient, $occurred, $email, $campaignId),
                'complained' => $this->complained($recipient, $occurred, $email, $campaignId),
                'opened' => $recipient && $this->engaged($recipient, 'open', $occurred),
                'clicked' => $recipient && $this->engaged($recipient, 'click', $occurred),
                'unsubscribed' => $recipient
                    ? $this->unsubscribes->unsubscribe($recipient, 'provider_webhook')
                    : ($email !== '' ? event(new ContactUnsubscribed($email, null, null, 'provider_webhook')) : null),
                default => null, // "accepted": stored for the audit trail only
            };
        });

        if ($campaignId) {
            RefreshCampaignStatsJob::dispatch($campaignId);
        }

        return $recipient ? 'processed' : 'unmatched';
    }

    private function match(WebhookEvent $e): ?CampaignRecipient
    {
        $recipient = null;

        if ($e->recipientToken) {
            $recipient = CampaignRecipient::where('tracking_token', $e->recipientToken)->first();
        }

        if (! $recipient && $e->messageId) {
            $recipient = CampaignRecipient::where('message_id', $e->messageId)->first();
        }

        if (! $recipient && $e->email) {
            $recipient = CampaignRecipient::query()
                ->where('email', mb_strtolower($e->email))
                ->when($e->campaignId, fn ($q) => $q->where('campaign_id', $e->campaignId))
                ->whereNotNull('sent_at')
                ->orderByDesc('id')
                ->first();
        }

        return $recipient;
    }

    private function delivered(CampaignRecipient $r, Carbon $at): void
    {
        CampaignRecipient::whereKey($r->id)->whereNull('delivered_at')->update(['delivered_at' => $at]);
        // Never overwrite a terminal state (bounced/complained/failed) with "delivered".
        CampaignRecipient::whereKey($r->id)->where('status', RecipientStatus::Sent->value)
            ->update(['status' => RecipientStatus::Delivered->value]);
    }

    private function bounced(WebhookEvent $e, ?CampaignRecipient $r, Carbon $at, string $email, ?int $campaignId): void
    {
        // Soft bounces are the provider's own retries: recorded, but they do not change recipient state.
        if ($r && $e->bounceType === 'hard') {
            CampaignRecipient::whereKey($r->id)->update([
                'status' => RecipientStatus::Bounced->value,
                'bounced_at' => $r->bounced_at ?? $at,
                'bounce_reason' => $e->reason ? mb_substr($e->reason, 0, 1000) : null,
            ]);
        }

        if ($email !== '') {
            event(new EmailBounced($email, $campaignId, $e->bounceType ?? 'hard', $e->reason));
        }
    }

    private function complained(?CampaignRecipient $r, Carbon $at, string $email, ?int $campaignId): void
    {
        if ($r) {
            CampaignRecipient::whereKey($r->id)->update([
                'status' => RecipientStatus::Complained->value,
                'complained_at' => $r->complained_at ?? $at,
            ]);
        }

        if ($email !== '') {
            event(new EmailComplained($email, $campaignId));
        }
    }

    private function engaged(CampaignRecipient $r, string $kind, Carbon $at): void
    {
        $first = $kind === 'open' ? 'first_opened_at' : 'first_clicked_at';

        CampaignRecipient::whereKey($r->id)->whereNull($first)->update([$first => $at]);
        CampaignRecipient::whereKey($r->id)->update([$kind.'_count' => DB::raw($kind.'_count + 1')]);
    }
}
