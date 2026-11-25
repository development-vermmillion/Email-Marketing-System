<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;

abstract class AbstractAdapter implements WebhookAdapter
{
    public function control(Request $request): ?string
    {
        return null;
    }

    /** @return array<mixed> */
    protected function json(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function cleanMessageId(?string $id): ?string
    {
        return $id ? trim($id, " \t\n\r\0\x0B<>") : null;
    }

    protected function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $parsed = strtotime((string) $value);

        return $parsed === false ? null : $parsed;
    }

    protected function str(mixed $value, int $limit = 1000): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, $limit) : null;
    }
}
