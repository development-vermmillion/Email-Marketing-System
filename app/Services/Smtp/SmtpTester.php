<?php

namespace App\Services\Smtp;

use App\Models\SmtpSetting;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmtpTester
{
    public function __construct(
        private SmtpMailerFactory $factory,
        private SmtpErrorClassifier $classifier,
    ) {}

    /** @return array{ok: bool, message: string} */
    public function testConnection(SmtpSetting $setting): array
    {
        try {
            $transport = $this->factory->make($setting)->getSymfonyTransport();

            if (method_exists($transport, 'start')) {
                $transport->start();
                $transport->stop();
            }

            $result = ['ok' => true, 'message' => 'Connected and authenticated successfully.'];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $this->classifier->sanitize($e->getMessage())];
            Log::warning('SMTP connection test failed', ['smtp_setting_id' => $setting->id, 'error' => $result['message']]);
        }

        $setting->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result['ok']])->save();

        return $result;
    }

    /** @return array{ok: bool, message: string} */
    public function sendTestEmail(SmtpSetting $setting, string $to): array
    {
        try {
            $this->factory->make($setting)->html(
                '<p>This is a test email from your Email Marketing System.</p><p>If you can read this, the SMTP settings <strong>'
                .e($setting->name).'</strong> work.</p>',
                function ($message) use ($setting, $to) {
                    $message->to($to)
                        ->from($setting->from_email, $setting->from_name)
                        ->subject('SMTP test: '.$setting->name);
                }
            );

            return ['ok' => true, 'message' => "Test email accepted by the relay for {$to}."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->classifier->sanitize($e->getMessage())];
        }
    }
}
