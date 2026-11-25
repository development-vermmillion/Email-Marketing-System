<?php

namespace App\Services;

use App\Models\SmtpSetting;
use App\Services\Smtp\SmtpErrorClassifier;
use App\Services\Smtp\SmtpMailerFactory;
use Throwable;

class TestEmailService
{
    public function __construct(
        private SmtpMailerFactory $mailers,
        private TemplateRenderer $renderer,
        private SmtpErrorClassifier $classifier,
    ) {}

    /**
     * Sends a single, sample-personalised test message through the given relay.
     * Test mails have no tracking and a placeholder unsubscribe link, and are never recorded as campaign sends.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(?SmtpSetting $smtp, string $to, string $subject, string $html, ?string $fromEmail = null, ?string $fromName = null, ?string $replyTo = null): array
    {
        $smtp ??= SmtpSetting::resolveDefault();

        if (! $smtp) {
            return ['ok' => false, 'message' => 'No active SMTP configuration. Add one under SMTP Settings first.'];
        }

        $body = $this->renderer->renderPreview($html);
        $plain = $this->renderer->toPlainText($body);
        $subject = '[TEST] '.preg_replace('/[\r\n]+/', ' ', $this->renderer->renderPreview($subject));

        try {
            $this->mailers->make($smtp)->html($body, function ($message) use ($smtp, $to, $subject, $plain, $fromEmail, $fromName, $replyTo) {
                $message->to($to)
                    ->from($fromEmail ?: $smtp->from_email, $fromName ?: $smtp->from_name)
                    ->subject(strip_tags($subject));

                if ($replyTo ?: $smtp->reply_to_email) {
                    $message->replyTo($replyTo ?: $smtp->reply_to_email);
                }

                $message->getSymfonyMessage()->text($plain);
            });

            return ['ok' => true, 'message' => "Test email sent to {$to}."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->classifier->sanitize($e->getMessage())];
        }
    }
}
