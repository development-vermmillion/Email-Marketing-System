<?php

namespace App\Services\Verification;

final class ProbeResult
{
    public const ACCEPTED = 'accepted';           // the server accepted this recipient (and rejected a made-up one)
    public const REJECTED = 'rejected';           // the server says this mailbox does not exist
    public const CATCH_ALL = 'catch_all';         // the server accepts any recipient, so nothing can be confirmed
    public const UNVERIFIABLE = 'unverifiable';   // blocked, greylisted, timed out, policy refusal: no conclusion

    public function __construct(
        public readonly string $outcome,
        public readonly ?bool $catchAll = null,   // domain-level finding, safe to cache; null = unknown
        public readonly ?string $detail = null,   // short technical note (never shown as an address verdict)
        public readonly bool $unreachable = false, // could not connect at all
    ) {}
}
