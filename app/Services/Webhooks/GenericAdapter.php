<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;

/**
 * Provider-agnostic JSON webhook, authenticated with an HMAC-SHA256 of the raw body sent in `X-Signature`
 * (hex). Useful for relays without a native adapter, or for forwarding events through your own middleware.
 *
 * Body: {"event":"delivered","email":"a@b.com","message_id":"...","recipient_token":"...","timestamp":1700000000,
 *        "id":"unique-event-id","reason":"...","bounce_type":"hard","url":"..."}   (single object, list, or {"events":[...]})
 */
class GenericAdapter extends AbstractAdapter
{
    private const MAP = [
        'accepted' => 'accepted', 'processed' => 'accepted', 'sent' => 'accepted',
        'delivered' => 'delivered', 'delivery' => 'delivered',
        'bounce' => 'bounced', 'bounced' => 'bounced', 'failed' => 'bounced',
        'complaint' => 'complained', 'complained' => 'complained', 'spamreport' => 'complained',
        'open' => 'opened', 'opened' => 'opened',
        'click' => 'clicked', 'clicked' => 'clicked',
        'unsubscribe' => 'unsubscribed', 'unsubscribed' => 'unsubscribed',
    ];

    public function provider(): string
    {
        return 'generic';
    }

    public function verify(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Signature', '');

        return $secret !== '' && $signature !== ''
            && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower($signature));
    }

    public function parse(Request $request): array
    {
        $payload = $this->json($request);
        $items = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : (array_is_list($payload) ? $payload : [$payload]);
        $events = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = self::MAP[strtolower((string) ($item['event'] ?? ''))] ?? null;

            if (! $type) {
                continue;
            }

            $events[] = new WebhookEvent(
                provider: 'generic',
                type: $type,
                email: $this->str($item['email'] ?? null, 255),
                occurredAt: $this->timestamp($item['timestamp'] ?? null),
                providerEventId: $this->str($item['id'] ?? null, 255),
                messageId: $this->cleanMessageId($this->str($item['message_id'] ?? null, 255)),
                recipientToken: $this->str($item['recipient_token'] ?? null, 64),
                campaignId: isset($item['campaign_id']) ? (int) $item['campaign_id'] : null,
                url: $this->str($item['url'] ?? null, 2000),
                bounceType: $type === 'bounced' ? (($item['bounce_type'] ?? 'hard') === 'soft' ? 'soft' : 'hard') : null,
                reason: $this->str($item['reason'] ?? null),
                raw: $item,
            );
        }

        return $events;
    }
}
