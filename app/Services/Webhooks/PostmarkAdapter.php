<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;

/**
 * Postmark webhooks. Postmark does not sign payloads, so authenticate with a shared secret: add a custom
 * header `X-Webhook-Token: <secret>` (or use HTTP Basic auth with the secret as password) in the webhook
 * settings. Put the same secret in the SMTP setting's "Webhook secret" field.
 */
class PostmarkAdapter extends AbstractAdapter
{
    public function provider(): string
    {
        return 'postmark';
    }

    public function verify(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $token = (string) $request->header('X-Webhook-Token', '');

        return ($token !== '' && hash_equals($secret, $token))
            || ($request->getPassword() !== null && hash_equals($secret, (string) $request->getPassword()));
    }

    public function parse(Request $request): array
    {
        $d = $this->json($request);

        $type = match ($d['RecordType'] ?? '') {
            'Delivery' => 'delivered',
            'Bounce' => 'bounced',
            'SpamComplaint' => 'complained',
            'Open' => 'opened',
            'Click' => 'clicked',
            'SubscriptionChange' => ($d['SuppressSending'] ?? false) ? 'unsubscribed' : null,
            default => null,
        };

        if (! $type) {
            return [];
        }

        $bounce = (string) ($d['Type'] ?? '');
        $hard = in_array($bounce, ['HardBounce', 'BadEmailAddress', 'Blocked', 'ManuallyDeactivated', 'Unsubscribe', 'SpamNotification', 'Unknown'], true);

        return [new WebhookEvent(
            provider: 'postmark',
            type: $type,
            email: $this->str($d['Recipient'] ?? $d['Email'] ?? null, 255),
            occurredAt: $this->timestamp($d['DeliveredAt'] ?? $d['BouncedAt'] ?? $d['ReceivedAt'] ?? null),
            providerEventId: $this->str(($d['RecordType'] ?? '').'-'.($d['ID'] ?? $d['MessageID'] ?? ''), 255),
            messageId: null,
            recipientToken: $this->str($d['Metadata']['recipient_token'] ?? null, 64),
            campaignId: isset($d['Metadata']['campaign_id']) ? (int) $d['Metadata']['campaign_id'] : null,
            url: $this->str($d['OriginalLink'] ?? null, 2000),
            bounceType: $type === 'bounced' ? ($hard ? 'hard' : 'soft') : null,
            reason: $this->str($d['Description'] ?? $d['Details'] ?? null),
            raw: $d,
        )];
    }
}
