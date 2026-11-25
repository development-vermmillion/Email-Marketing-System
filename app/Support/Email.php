<?php

namespace App\Support;

class Email
{
    public static function normalize(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /** Strict-enough syntactic validation (RFC 5322 subset) with a length guard matching the DB column. */
    public static function isValid(?string $email): bool
    {
        $email = self::normalize($email);

        return $email !== ''
            && mb_strlen($email) <= 255
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
