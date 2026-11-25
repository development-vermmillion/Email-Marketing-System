<?php

namespace App\Services\Webhooks;

final class WebhookEvent
{
    public const TYPES = ['accepted', 'delivered', 'bounced', 'complained', 'opened', 'clicked', 'unsubscribed'];

    /**
     * @param  string  $type  one of self::TYPES
     * @param  ?string  $bounceType  hard | soft
     * @param  array<string, mixed>  $raw  original provider payload for this event
     */
    public function __construct(
        public string $provider,
        public string $type,
        public ?string $email,
        public ?int $occurredAt,
        public ?string $providerEventId = null,
        public ?string $messageId = null,
        public ?string $recipientToken = null,
        public ?int $campaignId = null,
        public ?string $url = null,
        public ?string $bounceType = null,
        public ?string $reason = null,
        public array $raw = [],
    ) {}
}
