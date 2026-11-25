<?php

namespace App\Services\Smtp;

use Throwable;

class SmtpErrorClassifier
{
    public function classify(Throwable $e): SendFailure
    {
        $raw = $e->getMessage();
        $message = $this->sanitize($raw);
        $code = $this->extractCode($raw);

        // Relay-level problems: retrying each recipient would just burn attempts.
        if (in_array($code, ['530', '534', '535'], true)
            || preg_match('/failed to authenticate|authentication (failed|required)|connection could not be established|connection timed out|unable to connect|connection refused|stream_socket_client/i', $raw)) {
            return new SendFailure(SendFailure::SYSTEMIC, $code, $message);
        }

        if ($code !== null && $code[0] === '4') {
            return new SendFailure(SendFailure::TEMPORARY, $code, $message);
        }

        if ($code !== null && $code[0] === '5') {
            $rejectedRecipient = in_array($code, ['550', '551', '553'], true)
                && preg_match('/user unknown|no such user|mailbox (not found|unavailable|does not exist)|invalid (recipient|address|mailbox)|does not exist|recipient (rejected|address rejected)|unknown (user|recipient)/i', $raw) === 1;

            return new SendFailure(SendFailure::PERMANENT, $code, $message, $rejectedRecipient);
        }

        // Unknown failure: treat as temporary, bounded by the campaign's max attempts.
        return new SendFailure(SendFailure::TEMPORARY, $code, $message);
    }

    private function extractCode(string $raw): ?string
    {
        if (preg_match('/but got code "?(\d{3})"?/i', $raw, $m)) {
            return $m[1];
        }

        if (preg_match('/\b([45]\d{2})[ -]\d\.\d{1,3}\.\d{1,3}\b/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Remove anything that could carry credentials, flatten whitespace, cap the length stored in the DB. */
    public function sanitize(string $message): string
    {
        $message = preg_replace('/username\s+"[^"]*"/i', 'username "***"', $message);
        $message = preg_replace('/(password|passwd|pwd)\s*[=:]\s*\S+/i', '$1=***', (string) $message);
        $message = preg_replace('/\s+/', ' ', (string) $message);

        return mb_substr(trim((string) $message), 0, 500);
    }
}
