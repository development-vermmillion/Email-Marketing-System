<?php

namespace App\Support;

/** Outcome of checking one address. status: valid | risky | invalid. */
final class ValidationResult
{
    public const VALID = 'valid';
    public const RISKY = 'risky';
    public const INVALID = 'invalid';

    private const LABELS = [
        'syntax' => 'Not a valid email address format',
        'no_mail_server' => 'Domain cannot receive email (no mail server found)',
        'null_mx' => 'Domain explicitly does not accept email',
        'disposable' => 'Disposable / temporary email service',
        'role' => 'Shared team mailbox (e.g. info@, support@)',
        'typo' => 'Probably a typo in the domain name',
    ];

    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?string $suggestion = null,
    ) {}

    public static function label(?string $reason): string
    {
        return $reason === null ? '' : (self::LABELS[$reason] ?? $reason);
    }

    public function message(): string
    {
        return match (true) {
            $this->reason === null => 'Looks deliverable',
            $this->suggestion !== null => self::label($this->reason).' - did you mean '.$this->suggestion.'?',
            default => self::label($this->reason),
        };
    }

    /** @return array{status: string, reason: ?string, message: string, suggestion: ?string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'reason' => $this->reason, 'message' => $this->message(), 'suggestion' => $this->suggestion];
    }
}
