<?php

namespace App\Services\Verification;

use RuntimeException;

class StreamSmtpConnection implements SmtpConnection
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $port, int $timeout)
    {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT);

        if ($socket === false) {
            throw new RuntimeException("connect failed: {$error} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;
    }

    public function read(): array
    {
        $text = '';
        $code = 0;

        for ($lines = 0; $lines < 50; $lines++) {
            $line = fgets($this->socket, 1024);

            if ($line === false) {
                throw new RuntimeException('connection closed or timed out');
            }

            $text .= trim($line).' ';

            if (! preg_match('/^(\d{3})([ -])/', $line, $m)) {
                throw new RuntimeException('malformed reply');
            }

            $code = (int) $m[1];

            if ($m[2] === ' ') {   // "250 text" ends the reply; "250-text" continues it
                return [$code, trim($text)];
            }
        }

        throw new RuntimeException('reply too long');
    }

    public function write(string $command): void
    {
        if (@fwrite($this->socket, $command."\r\n") === false) {
            throw new RuntimeException('write failed');
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
