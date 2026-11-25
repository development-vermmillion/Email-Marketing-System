<?php

namespace App\Services;

use App\Models\CampaignRecipient;
use App\Models\Setting;

/**
 * Safe personalisation + tracking for HTML emails.
 *
 *  - Only whitelisted {{variables}} are substituted; unknown ones become empty strings.
 *  - Every contact-supplied value is HTML-escaped, so "<script>" in a name is inert.
 *  - Marketing mail must carry an unsubscribe link: if the template forgot {{unsubscribe_url}},
 *    a footer is appended automatically.
 */
class TemplateRenderer
{
    public const VARIABLES = ['name', 'first_name', 'email', 'company', 'phone', 'unsubscribe_url', 'sender_address'];

    public function render(string $html, CampaignRecipient $recipient, bool $tracking = true): string
    {
        $token = (string) $recipient->tracking_token;
        $unsubscribeUrl = route('unsubscribe.show', ['token' => $token]);
        $hadUnsubscribe = (bool) preg_match('/\{\{\s*unsubscribe_url\s*\}\}/i', $html);

        $out = $this->replaceVariables($html, $this->variables($recipient, $unsubscribeUrl));

        if (! $hadUnsubscribe) {
            $out = $this->injectBeforeBodyEnd($out, $this->fallbackFooter($unsubscribeUrl));
        }

        if ($tracking && Setting::get('track_clicks', '1') === '1') {
            $out = $this->rewriteLinks($out, $token, $unsubscribeUrl);
        }

        if ($tracking && Setting::get('track_opens', '1') === '1') {
            $pixel = '<img src="'.e(route('track.open', ['token' => $token])).'" width="1" height="1" alt="" style="display:none !important;border:0;">';
            $out = $this->injectBeforeBodyEnd($out, $pixel);
        }

        return $out;
    }

    /** Preview/test rendering: no tracking, placeholder unsubscribe link. */
    public function renderPreview(string $html, array $sample = []): string
    {
        $sample += ['name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'company' => 'Acme Inc.', 'phone' => '+91 98765 43210'];

        $vars = $this->variablesFromArray($sample, '#unsubscribe-preview');

        return $this->replaceVariables($html, $vars);
    }

    public function subject(string $subject, CampaignRecipient $recipient): string
    {
        // Subjects are plain text, not HTML: substitute raw values, strip control characters / newlines (header injection).
        $values = [
            'name' => (string) $recipient->name,
            'first_name' => $this->firstName($recipient->name),
            'email' => (string) $recipient->email,
            'company' => (string) $recipient->company,
            'phone' => (string) $recipient->phone,
        ];

        $result = preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn ($m) => $values[strtolower($m[1])] ?? '',
            $subject
        );

        return trim(preg_replace('/[\r\n\t]+/', ' ', (string) $result));
    }

    public function toPlainText(string $html): string
    {
        $text = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
        $text = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            function ($m) {
                $label = trim(strip_tags($m[3]));
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);

                return ($label === '' || $label === $url) ? $url : "{$label} ({$url})";
            },
            $text
        );
        $text = preg_replace('#<\s*br\s*/?>|</(p|div|tr|h[1-6]|li)>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n[ ]+/", "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /* ----------------------------------------------------------------- */

    /** @return array<string, string> already escaped, ready for HTML */
    private function variables(CampaignRecipient $r, string $unsubscribeUrl): array
    {
        return $this->variablesFromArray([
            'name' => $r->name,
            'email' => $r->email,
            'company' => $r->company,
            'phone' => $r->phone,
        ], $unsubscribeUrl);
    }

    /** @param array<string, ?string> $data */
    private function variablesFromArray(array $data, string $unsubscribeUrl): array
    {
        $esc = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'name' => $esc($data['name'] ?? ''),
            'first_name' => $esc($this->firstName($data['name'] ?? null)),
            'email' => $esc($data['email'] ?? ''),
            'company' => $esc($data['company'] ?? ''),
            'phone' => $esc($data['phone'] ?? ''),
            'unsubscribe_url' => $esc($unsubscribeUrl),
            'sender_address' => nl2br($esc((string) Setting::get('sender_address', ''))),
        ];
    }

    private function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? 'there' : (preg_split('/\s+/', $name)[0] ?? 'there');
    }

    /** @param array<string, string> $vars */
    private function replaceVariables(string $html, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn ($m) => $vars[strtolower($m[1])] ?? '',
            $html
        );
    }

    private function fallbackFooter(string $unsubscribeUrl): string
    {
        $address = nl2br(e((string) Setting::get('sender_address', '')));

        return '<div style="font-family:Arial,sans-serif;font-size:12px;color:#8b8fa3;text-align:center;padding:16px;">'
            .($address !== '' ? $address.'<br>' : '')
            .'<a href="'.e($unsubscribeUrl).'" style="color:#8b8fa3;">Unsubscribe</a></div>';
    }

    private function injectBeforeBodyEnd(string $html, string $fragment): string
    {
        $pos = strripos($html, '</body>');

        return $pos === false ? $html.$fragment : substr($html, 0, $pos).$fragment.substr($html, $pos);
    }

    private function rewriteLinks(string $html, string $token, string $unsubscribeUrl): string
    {
        return (string) preg_replace_callback(
            '/(<a\b[^>]*?\shref\s*=\s*)(["\'])(https?:\/\/[^"\']+)\2/i',
            function ($m) use ($token, $unsubscribeUrl) {
                $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);

                // Never wrap the unsubscribe link: it must work even if tracking is down.
                if (str_starts_with($url, $unsubscribeUrl) || str_contains($url, '/unsubscribe/')) {
                    return $m[0];
                }

                return $m[1].$m[2].e(self::trackedUrl($token, $url)).$m[2];
            },
            $html
        );
    }

    public static function trackedUrl(string $token, string $url): string
    {
        return route('track.click', [
            'token' => $token,
            'u' => self::base64UrlEncode($url),
            's' => self::signature($token, $url),
        ]);
    }

    public static function signature(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$url, (string) config('app.key')), 0, 32);
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
