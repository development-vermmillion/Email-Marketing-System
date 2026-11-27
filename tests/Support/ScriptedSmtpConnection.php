<?php

namespace Tests\Support;

use App\Services\Verification\SmtpConnection;
use RuntimeException;

/** A mail server that answers from a fixed script and records what it was sent. Nothing touches the network. */
class ScriptedSmtpConnection implements SmtpConnection
{
    /** @var list<string> */
    public array $sent = [];

    /** @param list<array{0:int,1:string}> $replies */
    public function __construct(private array $replies) {}

    public function read(): array
    {
        return array_shift($this->replies) ?? throw new RuntimeException('script exhausted');
    }

    public function write(string $command): void
    {
        $this->sent[] = $command;
    }

    public function close(): void {}
}
