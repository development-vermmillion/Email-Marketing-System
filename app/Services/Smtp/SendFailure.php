<?php

namespace App\Services\Smtp;

final class SendFailure
{
    public const TEMPORARY = 'temporary';
    public const PERMANENT = 'permanent';
    /** Problem with the relay itself (bad credentials, unreachable): pause the campaign instead of failing recipients. */
    public const SYSTEMIC = 'systemic';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $code,
        public readonly string $message,
        public readonly bool $suppressRecipient = false,
    ) {}
}
