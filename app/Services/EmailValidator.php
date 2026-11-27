<?php

namespace App\Services;

use App\Support\Email;
use App\Support\EmailLists;
use App\Services\Verification\ProbeResult;
use App\Services\Verification\SmtpProber;
use App\Support\ValidationResult;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Checks an address WITHOUT contacting the recipient's mail server:
 *   syntax -> role account -> disposable domain -> domain typo -> DNS (MX, falling back to A/AAAA).
 *
 * It does not (and cannot reliably) confirm that a specific mailbox exists: SMTP "RCPT" probing is blocked or
 * misleading on many servers and can hurt your sender reputation. "valid" therefore means "the domain can receive
 * mail and nothing looks wrong", not "this person's inbox exists". Hard bounces still feed the suppression list.
 */
class EmailValidator
{
    /** @var array<string, string> per-process memo: domain => ok | none | null_mx */
    private array $domainMemo = [];

    /** @var (Closure(string): string)|null */
    private ?Closure $resolver = null;

    /** @var (Closure(string): list<string>)|null */
    private ?Closure $mxResolver = null;

    private ?SmtpProber $prober = null;

    /** @var array<string, float> domain => microtime of the last mailbox probe (politeness delay) */
    private array $lastProbeAt = [];

    /** Replace the mail-server lookup used by the mailbox check (used by tests). */
    public function useMxResolver(?Closure $resolver): static
    {
        $this->mxResolver = $resolver;

        return $this;
    }

    public function useProber(?SmtpProber $prober): static
    {
        $this->prober = $prober;

        return $this;
    }

    /** Replace the DNS lookup (used by tests). The closure returns "ok", "none" or "null_mx". */
    public function useResolver(?Closure $resolver): static
    {
        $this->resolver = $resolver;
        $this->domainMemo = [];

        return $this;
    }

    public function validate(?string $email, bool $deep = false): ValidationResult
    {
        $email = Email::normalize($email);

        if (! Email::isValid($email) || str_contains($email, '..') || strlen(strstr($email, '@', true)) > 64) {
            return new ValidationResult(ValidationResult::INVALID, 'syntax');
        }

        [$local, $domain] = explode('@', $email, 2);

        if (! str_contains($domain, '.')) {
            return new ValidationResult(ValidationResult::INVALID, 'syntax');
        }

        // A typo domain may well exist (gmial.com is registered), so this is "risky", never skipped by DNS.
        if (isset(EmailLists::DOMAIN_TYPOS[$domain])) {
            return new ValidationResult(ValidationResult::RISKY, 'typo', $local.'@'.EmailLists::DOMAIN_TYPOS[$domain]);
        }

        if (config('mailing.validation.dns', true)) {
            $dns = $this->domainStatus($domain);

            if ($dns === 'null_mx') {
                return new ValidationResult(ValidationResult::INVALID, 'null_mx');
            }
            if ($dns === 'none') {
                return new ValidationResult(ValidationResult::INVALID, 'no_mail_server');
            }
        }

        if ($this->isDisposable($domain)) {
            return new ValidationResult(ValidationResult::RISKY, 'disposable');
        }

        if (in_array($local, EmailLists::ROLE_ACCOUNTS, true)) {
            return new ValidationResult(ValidationResult::RISKY, 'role');
        }

        if ($deep && config('mailing.validation.smtp_probe')) {
            return $this->checkMailbox($local, $domain);
        }

        return new ValidationResult(ValidationResult::VALID);
    }

    /**
     * Mailbox-level check. Only a clear "no such mailbox" makes an address invalid; when the server cannot be
     * asked (port blocked, greylisting, policy refusal) the DNS-level verdict stands and is marked "unverified".
     */
    private function checkMailbox(string $local, string $domain): ValidationResult
    {
        $email = $local.'@'.$domain;
        $unverified = new ValidationResult(ValidationResult::VALID, 'unverified');

        if (Cache::get('emailval:probe:off') || Cache::get('emailval:probe:down:'.$domain)) {
            return $unverified;
        }

        $catchAll = Cache::get('emailval:catchall:'.$domain); // 'yes' | 'no' | null (unknown)

        if ($catchAll === 'yes') {
            return new ValidationResult(ValidationResult::RISKY, 'catch_all');
        }

        $this->politenessDelay($domain);

        $result = $this->prober()->probe($email, $this->mxHosts($domain), $catchAll !== 'no');
        $this->lastProbeAt[$domain] = microtime(true);

        $this->trackReachability($domain, $result);

        if ($result->catchAll !== null) {
            Cache::put('emailval:catchall:'.$domain, $result->catchAll ? 'yes' : 'no', now()->addDay());
        }

        return match ($result->outcome) {
            ProbeResult::ACCEPTED => new ValidationResult(ValidationResult::VALID, 'mailbox_confirmed'),
            ProbeResult::REJECTED => new ValidationResult(ValidationResult::INVALID, 'mailbox_not_found'),
            ProbeResult::CATCH_ALL => new ValidationResult(ValidationResult::RISKY, 'catch_all'),
            default => $unverified,
        };
    }

    /**
     * Stops us waiting for timeouts over and over: a domain whose servers cannot be reached is skipped for a while,
     * and if many different domains in a row cannot be reached, port 25 is almost certainly blocked on this
     * machine, so mailbox checks are paused for ten minutes.
     */
    private function trackReachability(string $domain, ProbeResult $result): void
    {
        if (! $result->unreachable) {
            Cache::forget('emailval:probe:streak');

            return;
        }

        Cache::put('emailval:probe:down:'.$domain, true, now()->addMinutes(30));
        $streak = (int) Cache::get('emailval:probe:streak', 0) + 1;
        Cache::put('emailval:probe:streak', $streak, now()->addMinutes(10));

        if ($streak >= 8) {
            Cache::put('emailval:probe:off', true, now()->addMinutes(10));
        }
    }

    private function politenessDelay(string $domain): void
    {
        $gap = max(0, (int) config('mailing.validation.probe_delay_ms', 1000)) / 1000;
        $wait = ($this->lastProbeAt[$domain] ?? 0) + $gap - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }

    private function prober(): SmtpProber
    {
        if ($this->prober) {
            return $this->prober;
        }

        $host = config('mailing.validation.probe_helo') ?: (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        return $this->prober = new SmtpProber(
            helo: $host,
            mailFrom: config('mailing.validation.probe_mail_from') ?: 'verify@'.$host,
            timeout: (int) config('mailing.validation.probe_timeout', 8),
            port: (int) config('mailing.validation.probe_port', 25),
        );
    }

    /** @return list<string> mail servers for the domain, highest priority first */
    private function mxHosts(string $domain): array
    {
        if ($this->mxResolver) {
            return ($this->mxResolver)($domain);
        }

        $records = @dns_get_record($domain.'.', DNS_MX);

        if (is_array($records) && $records !== []) {
            usort($records, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));

            return array_values(array_filter(array_map(fn ($r) => trim((string) ($r['target'] ?? ''), '.'), $records)));
        }

        return [$domain]; // no MX: the domain's own address receives the mail
    }

    /** Whether mailbox checks can run right now (switched on and not paused by the port-25 circuit breaker). */
    public static function probeAvailable(): bool
    {
        return (bool) config('mailing.validation.smtp_probe') && ! Cache::get('emailval:probe:off');
    }

    private function isDisposable(string $domain): bool
    {
        foreach (EmailLists::DISPOSABLE_DOMAINS as $bad) {
            if ($domain === $bad || str_ends_with($domain, '.'.$bad)) {
                return true;
            }
        }

        return false;
    }

    /** @return 'ok'|'none'|'null_mx' */
    private function domainStatus(string $domain): string
    {
        if (isset($this->domainMemo[$domain])) {
            return $this->domainMemo[$domain];
        }

        $key = 'emailval:dns:'.$domain;
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $this->domainMemo[$domain] = $cached;
        }

        $status = $this->resolver ? ($this->resolver)($domain) : $this->lookup($domain);

        // Remember good answers for a day; remember "none" only briefly, since a failed lookup can be a transient DNS hiccup.
        Cache::put($key, $status, $status === 'ok' ? now()->addDay() : now()->addMinutes(30));

        return $this->domainMemo[$domain] = $status;
    }

    /** @return 'ok'|'none'|'null_mx' */
    private function lookup(string $domain): string
    {
        // One retry: a single timeout must not condemn a good domain.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $mx = @dns_get_record($domain.'.', DNS_MX);

            if (is_array($mx) && $mx !== []) {
                $targets = array_filter(array_map(fn ($r) => trim((string) ($r['target'] ?? ''), '.'), $mx), fn ($t) => $t !== '');

                // RFC 7505 "null MX": a single "." target means the domain never accepts mail.
                return $targets === [] ? 'null_mx' : 'ok';
            }

            // No MX: mail falls back to the domain's own A/AAAA record (RFC 5321 section 5.1).
            $addr = @dns_get_record($domain.'.', DNS_A | DNS_AAAA);

            if (is_array($addr) && $addr !== []) {
                return 'ok';
            }
        }

        return 'none';
    }
}
