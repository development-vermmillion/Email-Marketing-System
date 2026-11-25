<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;

interface WebhookAdapter
{
    public function provider(): string;

    /** Authenticate the request using the shared secret / public key configured on the SMTP setting. */
    public function verify(Request $request, string $secret): bool;

    /** @return list<WebhookEvent> */
    public function parse(Request $request): array;

    /**
     * Handles provider control messages (e.g. SNS subscription confirmation).
     * Return a short description if the request was a control message, otherwise null.
     */
    public function control(Request $request): ?string;
}
