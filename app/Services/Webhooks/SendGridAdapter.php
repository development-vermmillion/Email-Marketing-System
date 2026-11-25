<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;

/**
 * SendGrid Signed Event Webhook (ECDSA). Store the *verification key* (public key, base64) from the SendGrid
 * webhook settings in the SMTP setting's "Webhook secret" field.
 */
class SendGridAdapter extends AbstractAdapter
{
    public function provider(): string
    {
        return 'sendgrid';
    }

    public function verify(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Twilio-Email-Event-Webhook-Signature', '');
        $timestamp = (string) $request->header('X-Twilio-Email-Event-Webhook-Timestamp', '');

        if ($secret === '' || $signature === '' || $timestamp === '' || abs(time() - (int) $timestamp) > 600) {
            return false;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(trim($secret), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $decoded = base64_decode($signature, true);

        if ($key === false || $decoded === false) {
            return false;
        }

        return openssl_verify($timestamp.$request->getContent(), $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function parse(Request $request): array
    {
        $events = [];

        foreach ($this->json($request) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = (string) ($item['event'] ?? '');
            $type = match ($name) {
                'processed' => 'accepted',
                'delivered' => 'delivered',
                'bounce', 'blocked', 'dropped' => 'bounced',
                'spamreport' => 'complained',
                'open' => 'opened',
                'click' => 'clicked',
                'unsubscribe', 'group_unsubscribe' => 'unsubscribed',
                default => null, // deferred etc.: the provider is still retrying
            };

            if (! $type) {
                continue;
            }

            $reason = $this->str($item['reason'] ?? null);
            $hard = $name === 'bounce' && ($item['type'] ?? 'bounce') !== 'blocked'
                || ($name === 'dropped' && preg_match('/bounce|invalid|unsubscrib|spam/i', (string) $reason));

            $events[] = new WebhookEvent(
                provider: 'sendgrid',
                type: $type,
                email: $this->str($item['email'] ?? null, 255),
                occurredAt: $this->timestamp($item['timestamp'] ?? null),
                providerEventId: $this->str($item['sg_event_id'] ?? null, 255),
                messageId: $this->cleanMessageId($this->str($item['smtp-id'] ?? null, 255)),
                recipientToken: $this->str($item['recipient_token'] ?? null, 64),
                campaignId: isset($item['campaign_id']) ? (int) $item['campaign_id'] : null,
                url: $this->str($item['url'] ?? null, 2000),
                bounceType: $type === 'bounced' ? ($hard ? 'hard' : 'soft') : null,
                reason: $reason,
                raw: $item,
            );
        }

        return $events;
    }
}
