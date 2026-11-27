<?php

namespace App\Services;

use App\Support\Email;
use App\Support\EmailLists;
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

    /** Replace the DNS lookup (used by tests). The closure returns "ok", "none" or "null_mx". */
    public function useResolver(?Closure $resolver): static
    {
        $this->resolver = $resolver;
        $this->domainMemo = [];

        return $this;
    }

    public function validate(?string $email): ValidationResult
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

        return new ValidationResult(ValidationResult::VALID);
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
