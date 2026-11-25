<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Amazon SES event publishing delivered through an SNS HTTPS subscription. Authentication is the SNS message
 * signature (certificate fetched from an *.amazonaws.com host and verified). The SMTP setting's "Webhook secret"
 * must hold the SNS Topic ARN so that only YOUR topic is accepted.
 *
 * SES rewrites the Message-ID header, so events are matched through the `recipient_token` message tag that the
 * sender adds (X-SES-MESSAGE-TAGS); enable "Message tags" in the configuration set's event destination.
 */
class SesAdapter extends AbstractAdapter
{
    public function provider(): string
    {
        return 'ses';
    }

    public function verify(Request $request, string $secret): bool
    {
        $m = $this->json($request);

        if ($secret === '' || ($m['TopicArn'] ?? null) !== $secret || ! isset($m['Signature'], $m['SigningCertURL'], $m['Type'])) {
            return false;
        }

        $url = (string) $m['SigningCertURL'];
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || ! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $host)) {
            return false;
        }

        $cert = Cache::remember('sns.cert.'.sha1($url), 86400, fn () => Http::timeout(5)->get($url)->body());
        $string = $this->stringToSign($m);
        $algo = ($m['SignatureVersion'] ?? '1') === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $sig = base64_decode((string) $m['Signature'], true);

        if ($string === null || $sig === false || ! ($key = openssl_pkey_get_public($cert))) {
            return false;
        }

        return openssl_verify($string, $sig, $key, $algo) === 1;
    }

    /** @param array<string, mixed> $m */
    private function stringToSign(array $m): ?string
    {
        $keys = match ($m['Type']) {
            'Notification' => isset($m['Subject'])
                ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
                : ['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
            default => null,
        };

        if ($keys === null) {
            return null;
        }

        $out = '';
        foreach ($keys as $key) {
            if (! isset($m[$key])) {
                return null;
            }
            $out .= $key."\n".$m[$key]."\n";
        }

        return $out;
    }

    public function control(Request $request): ?string
    {
        $m = $this->json($request);

        if (($m['Type'] ?? '') !== 'SubscriptionConfirmation') {
            return null;
        }

        $url = (string) ($m['SubscribeURL'] ?? '');

        if (preg_match('#^https://sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?/#', $url)) {
            Http::timeout(5)->get($url);
            Log::info('SES SNS subscription confirmed');
        }

        return 'subscription confirmed';
    }

    public function parse(Request $request): array
    {
        $envelope = $this->json($request);

        if (($envelope['Type'] ?? '') !== 'Notification') {
            return [];
        }

        $m = json_decode((string) ($envelope['Message'] ?? ''), true);

        if (! is_array($m)) {
            return [];
        }

        $kind = $m['eventType'] ?? $m['notificationType'] ?? '';
        $mail = $m['mail'] ?? [];
        $token = $this->str($mail['tags']['recipient_token'][0] ?? null, 64);
        $campaign = isset($mail['tags']['campaign_id'][0]) ? (int) $mail['tags']['campaign_id'][0] : null;
        $messageId = $this->cleanMessageId($this->str($mail['commonHeaders']['messageId'] ?? null, 255));
        $base = (string) ($envelope['MessageId'] ?? '');
        $make = fn (string $type, ?string $email, mixed $time, array $extra = []) => new WebhookEvent(
            provider: 'ses',
            type: $type,
            email: $email,
            occurredAt: $this->timestamp($time),
            providerEventId: $this->str($base.'|'.$type.'|'.$email, 255),
            messageId: $messageId,
            recipientToken: $token,
            campaignId: $campaign,
            url: $extra['url'] ?? null,
            bounceType: $extra['bounceType'] ?? null,
            reason: $extra['reason'] ?? null,
            raw: $m,
        );

        $events = [];

        switch ($kind) {
            case 'Send':
                foreach ($mail['destination'] ?? [] as $email) {
                    $events[] = $make('accepted', $this->str($email, 255), $mail['timestamp'] ?? null);
                }
                break;
            case 'Delivery':
                foreach ($m['delivery']['recipients'] ?? [] as $email) {
                    $events[] = $make('delivered', $this->str($email, 255), $m['delivery']['timestamp'] ?? null);
                }
                break;
            case 'Bounce':
                foreach ($m['bounce']['bouncedRecipients'] ?? [] as $r) {
                    $events[] = $make('bounced', $this->str($r['emailAddress'] ?? null, 255), $m['bounce']['timestamp'] ?? null, [
                        'bounceType' => ($m['bounce']['bounceType'] ?? '') === 'Permanent' ? 'hard' : 'soft',
                        'reason' => $this->str($r['diagnosticCode'] ?? $m['bounce']['bounceSubType'] ?? null),
                    ]);
                }
                break;
            case 'Complaint':
                foreach ($m['complaint']['complainedRecipients'] ?? [] as $r) {
                    $events[] = $make('complained', $this->str($r['emailAddress'] ?? null, 255), $m['complaint']['timestamp'] ?? null);
                }
                break;
            case 'Open':
                $events[] = $make('opened', $this->str($mail['destination'][0] ?? null, 255), $m['open']['timestamp'] ?? null);
                break;
            case 'Click':
                $events[] = $make('clicked', $this->str($mail['destination'][0] ?? null, 255), $m['click']['timestamp'] ?? null, ['url' => $this->str($m['click']['link'] ?? null, 2000)]);
                break;
        }

        return $events;
    }
}
