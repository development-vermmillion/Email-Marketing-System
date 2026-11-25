<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;

/** Mailgun webhooks. Store the "HTTP webhook signing key" in the SMTP setting's "Webhook secret" field. */
class MailgunAdapter extends AbstractAdapter
{
    public function provider(): string
    {
        return 'mailgun';
    }

    public function verify(Request $request, string $secret): bool
    {
        $sig = $this->json($request)['signature'] ?? [];
        $timestamp = (string) ($sig['timestamp'] ?? '');
        $token = (string) ($sig['token'] ?? '');
        $signature = (string) ($sig['signature'] ?? '');

        if ($secret === '' || $timestamp === '' || $token === '' || $signature === '' || abs(time() - (int) $timestamp) > 900) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.$token, $secret), $signature);
    }

    public function parse(Request $request): array
    {
        $data = $this->json($request)['event-data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        $severity = $data['severity'] ?? null;
        $type = match ($data['event'] ?? '') {
            'accepted' => 'accepted',
            'delivered' => 'delivered',
            'failed' => $severity === 'permanent' ? 'bounced' : null, // temporary failures are retried by Mailgun
            'complained' => 'complained',
            'opened' => 'opened',
            'clicked' => 'clicked',
            'unsubscribed' => 'unsubscribed',
            default => null,
        };

        if (! $type) {
            return [];
        }

        $reason = $this->str($data['delivery-status']['message'] ?? ($data['reason'] ?? null));

        return [new WebhookEvent(
            provider: 'mailgun',
            type: $type,
            email: $this->str($data['recipient'] ?? null, 255),
            occurredAt: $this->timestamp($data['timestamp'] ?? null),
            providerEventId: $this->str($data['id'] ?? null, 255),
            messageId: $this->cleanMessageId($this->str($data['message']['headers']['message-id'] ?? null, 255)),
            recipientToken: $this->str($data['user-variables']['recipient_token'] ?? null, 64),
            campaignId: isset($data['user-variables']['campaign_id']) ? (int) $data['user-variables']['campaign_id'] : null,
            url: $this->str($data['url'] ?? null, 2000),
            bounceType: $type === 'bounced' ? 'hard' : null,
            reason: $reason,
            raw: $data,
        )];
    }
}
