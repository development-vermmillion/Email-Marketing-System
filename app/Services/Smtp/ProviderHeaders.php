<?php

namespace App\Services\Smtp;

use Symfony\Component\Mime\Email;

/**
 * Adds the provider-specific metadata header that makes webhook events come back with our
 * recipient token, so events can be matched to a campaign recipient exactly.
 */
class ProviderHeaders
{
    public static function apply(Email $message, string $provider, string $token, int $campaignId): void
    {
        $headers = $message->getHeaders();

        match ($provider) {
            'sendgrid' => $headers->addTextHeader('X-SMTPAPI', json_encode(
                ['unique_args' => ['recipient_token' => $token, 'campaign_id' => (string) $campaignId]]
            )),
            'mailgun' => $headers->addTextHeader('X-Mailgun-Variables', json_encode(
                ['recipient_token' => $token, 'campaign_id' => (string) $campaignId]
            )),
            'postmark' => (function () use ($headers, $token, $campaignId) {
                $headers->addTextHeader('X-PM-Metadata-recipient_token', $token);
                $headers->addTextHeader('X-PM-Metadata-campaign_id', (string) $campaignId);
            })(),
            'ses' => $headers->addTextHeader('X-SES-MESSAGE-TAGS', "recipient_token={$token}, campaign_id={$campaignId}"),
            default => null,
        };
    }
}
