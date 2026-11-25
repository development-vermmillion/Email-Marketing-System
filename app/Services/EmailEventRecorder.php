<?php

namespace App\Services;

use App\Models\EmailEvent;
use Illuminate\Database\UniqueConstraintViolationException;

class EmailEventRecorder
{
    /**
     * Stores a normalised event. Returns null when an identical event (same dedupe key) was already
     * stored - that is how webhook retries and double-clicks become no-ops.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $dedupeKey, array $attributes): ?EmailEvent
    {
        try {
            return EmailEvent::create($attributes + ['dedupe_key' => hash('sha256', $dedupeKey)]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
