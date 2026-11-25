<?php

namespace Tests\Support;

use App\Models\SmtpSetting;
use App\Services\Smtp\SmtpMailerFactory;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/** Replaces the SMTP transport with Laravel's in-memory "array" transport so tests never touch the network. */
class FakeMailerFactory extends SmtpMailerFactory
{
    public function make(SmtpSetting $setting): Mailer
    {
        return Mail::build(['transport' => 'array']);
    }
}
