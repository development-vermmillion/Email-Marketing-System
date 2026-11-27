<?php

namespace App\Services\Verification;

/** One open conversation with a mail server. Tests replace it with a scripted fake. */
interface SmtpConnection
{
    /** Reads one complete (possibly multi-line) reply. @return array{0: int, 1: string} [code, text] */
    public function read(): array;

    public function write(string $command): void;

    public function close(): void;
}
