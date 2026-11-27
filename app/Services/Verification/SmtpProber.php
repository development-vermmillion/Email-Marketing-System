<?php

namespace App\Services\Verification;

use Closure;
use Throwable;

/**
 * Asks a recipient's mail server whether it would accept an address, WITHOUT sending a message:
 *   connect -> EHLO -> MAIL FROM -> RCPT TO (the address) -> RCPT TO (a made-up address, to spot catch-all servers) -> QUIT.
 * DATA is never sent, so nothing is ever delivered.
 *
 * Be conservative: only a clear "no such mailbox" answer counts as a rejection. Policy refusals, blocks, greylisting
 * and timeouts mean "we could not tell", never "invalid", otherwise our own IP being blocked would delete good contacts.
 */
class SmtpProber
{
    private const POLICY = '/block|spam|black\s?list|\brbl\b|reputation|denied|not allowed|policy|relay|access|\bptr\b|reverse dns|rdns|dnsbl|spamhaus|authenticat|too many|rate.?limit|throttl|try again|temporar|greylist|\b5\.7\.\d+\b|\b5\.4\.\d+\b/i';
    private const NO_MAILBOX = '/\b5\.1\.[1236]\b|\b5\.1\.10\b|\b5\.2\.1\b|user unknown|unknown user|no such user|no such (mail)?box|does not exist|doesn\'t exist|invalid (recipient|mailbox|address)|recipient (not found|rejected|unknown)|mailbox (unavailable|not found|disabled)|no mailbox|address rejected|undeliverable|unrouteable|account.*(disabled|inactive)/i';

    /** @var (Closure(string, int, int): SmtpConnection)|null */
    private ?Closure $connector;

    public function __construct(
        private string $helo,
        private string $mailFrom,
        private int $timeout = 8,
        private int $port = 25,
        ?Closure $connector = null,
    ) {
        $this->connector = $connector;
    }

    /**
     * @param  list<string>  $hosts  mail servers for the domain, best first (at most two are tried)
     * @param  bool  $checkCatchAll  false when the domain is already known not to be catch-all
     */
    public function probe(string $email, array $hosts, bool $checkCatchAll = true): ProbeResult
    {
        $domain = substr(strrchr($email, '@'), 1);
        $last = new ProbeResult(ProbeResult::UNVERIFIABLE, null, 'no mail server could be reached', true);

        foreach (array_slice($hosts, 0, 2) as $host) {
            try {
                $conn = $this->connector
                    ? ($this->connector)($host, $this->port, $this->timeout)
                    : new StreamSmtpConnection($host, $this->port, $this->timeout);
            } catch (Throwable $e) {
                $last = new ProbeResult(ProbeResult::UNVERIFIABLE, null, 'could not connect to '.$host, true);

                continue;
            }

            try {
                return $this->converse($conn, $email, $domain, $checkCatchAll);
            } catch (Throwable $e) {
                $last = new ProbeResult(ProbeResult::UNVERIFIABLE, null, 'conversation failed: '.$e->getMessage());
            } finally {
                $conn->close();
            }
        }

        return $last;
    }

    private function converse(SmtpConnection $c, string $email, string $domain, bool $checkCatchAll): ProbeResult
    {
        [$code, $text] = $c->read();

        if ($code !== 220) {
            return new ProbeResult(ProbeResult::UNVERIFIABLE, null, "greeting refused ({$code})");
        }

        $c->write('EHLO '.$this->helo);
        [$code] = $c->read();

        if ($code !== 250) {
            $c->write('HELO '.$this->helo);
            [$code] = $c->read();

            if ($code !== 250) {
                return new ProbeResult(ProbeResult::UNVERIFIABLE, null, 'HELO refused');
            }
        }

        $c->write('MAIL FROM:<'.$this->mailFrom.'>');
        [$code, $text] = $c->read();

        if ($code !== 250) {
            return new ProbeResult(ProbeResult::UNVERIFIABLE, null, "sender refused ({$code})");
        }

        $c->write('RCPT TO:<'.$email.'>');
        [$code, $text] = $c->read();
        $verdict = $this->classify($code, $text);

        if ($verdict !== ProbeResult::ACCEPTED) {
            $this->quit($c);

            return new ProbeResult($verdict, null, "RCPT {$code}");
        }

        if (! $checkCatchAll) {
            $this->quit($c);

            return new ProbeResult(ProbeResult::ACCEPTED, false);
        }

        // A server that accepts a random address accepts everything, so acceptance of the real one proves nothing.
        $c->write('RCPT TO:<'.'verify-'.bin2hex(random_bytes(6)).'@'.$domain.'>');
        [$code2, $text2] = $c->read();
        $this->quit($c);

        if ($this->classify($code2, $text2) === ProbeResult::ACCEPTED) {
            return new ProbeResult(ProbeResult::CATCH_ALL, true);
        }

        // A clear refusal of the made-up address means the server really does check mailboxes.
        return new ProbeResult(ProbeResult::ACCEPTED, $code2 >= 500 ? false : null);
    }

    public function classify(int $code, string $text): string
    {
        if ($code >= 200 && $code < 300) {
            return ProbeResult::ACCEPTED;
        }

        if ($code >= 500 && ! preg_match(self::POLICY, $text) && preg_match(self::NO_MAILBOX, $text)) {
            return ProbeResult::REJECTED;
        }

        return ProbeResult::UNVERIFIABLE; // 4xx greylisting, policy refusals, anything unclear
    }

    private function quit(SmtpConnection $c): void
    {
        try {
            $c->write('QUIT');
        } catch (Throwable) {
            // closing anyway
        }
    }
}
