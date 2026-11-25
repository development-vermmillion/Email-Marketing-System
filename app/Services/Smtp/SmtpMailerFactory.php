<?php

namespace App\Services\Smtp;

use App\Models\SmtpSetting;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Builds a Laravel mailer from a stored SmtpSetting at runtime. This is the seam that lets the
 * system support several SMTP providers: the sending engine only ever asks for "a mailer for this setting".
 */
class SmtpMailerFactory
{
    public function make(SmtpSetting $setting): Mailer
    {
        return Mail::build([
            'transport' => 'smtp',
            // "ssl" = implicit TLS (smtps, typically port 465). "tls" = STARTTLS (typically 587).
            'scheme' => $setting->encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $setting->host,
            'port' => $setting->port,
            'username' => $setting->username,
            'password' => $setting->password,
            'timeout' => 30,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost',
        ]);
    }
}
