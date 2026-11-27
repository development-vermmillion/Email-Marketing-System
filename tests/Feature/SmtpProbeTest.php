<?php

namespace Tests\Feature;

use App\Models\Audience;
use App\Models\EmailValidationRun;
use App\Models\User;
use App\Jobs\ValidateEmailsJob;
use App\Services\EmailValidator;
use App\Services\Verification\ProbeResult;
use App\Services\Verification\SmtpProber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ScriptedSmtpConnection;
use Tests\TestCase;

class SmtpProbeTest extends TestCase
{
    use RefreshDatabase;

    private function prober(array $replies, ?ScriptedSmtpConnection &$conn = null): SmtpProber
    {
        $conn = new ScriptedSmtpConnection($replies);

        return new SmtpProber('probe.test', 'verify@probe.test', 5, 25, fn () => $conn);
    }

    private function greeting(): array
    {
        return [[220, 'mx ready'], [250, 'hello'], [250, 'sender ok']];
    }

    public function test_accepted_recipient_with_rejected_random_address_is_confirmed(): void
    {
        $p = $this->prober([...$this->greeting(), [250, 'ok'], [550, '5.1.1 user unknown']], $conn);

        $r = $p->probe('jane@example.com', ['mx.example.com']);

        $this->assertSame(ProbeResult::ACCEPTED, $r->outcome);
        $this->assertFalse($r->catchAll);
        $this->assertSame('RCPT TO:<jane@example.com>', $conn->sent[2]);
        $this->assertNotContains('DATA', $conn->sent); // nothing is ever sent
    }

    public function test_server_that_accepts_a_random_address_is_catch_all(): void
    {
        $r = $this->prober([...$this->greeting(), [250, 'ok'], [250, 'ok']])->probe('jane@example.com', ['mx.example.com']);

        $this->assertSame(ProbeResult::CATCH_ALL, $r->outcome);
        $this->assertTrue($r->catchAll);
    }

    public function test_clear_no_such_mailbox_answers_are_rejections(): void
    {
        foreach ([
            '550 5.1.1 The email account that you tried to reach does not exist.',
            '550 5.1.10 RESOLVER.ADR.RecipientNotFound; Recipient not found',
            '550 <x@y.com>: Recipient address rejected: User unknown in virtual mailbox table',
        ] as $text) {
            $code = (int) substr($text, 0, 3);
            $r = $this->prober([...$this->greeting(), [$code, $text]])->probe('x@y.com', ['mx.y.com']);
            $this->assertSame(ProbeResult::REJECTED, $r->outcome, $text);
        }
    }

    public function test_policy_blocks_greylisting_and_unclear_answers_are_never_rejections(): void
    {
        foreach ([
            [550, '5.7.1 Service unavailable; client host blocked using Spamhaus'],
            [554, '5.7.1 Relay access denied'],
            [451, '4.7.1 Greylisted, please try again later'],
            [550, 'Mailbox policy violation'],
            [550, 'Requested action not taken'],
        ] as [$code, $text]) {
            $r = $this->prober([...$this->greeting(), [$code, $text]])->probe('x@y.com', ['mx.y.com']);
            $this->assertSame(ProbeResult::UNVERIFIABLE, $r->outcome, "{$code} {$text}");
        }
    }

    public function test_refused_greeting_or_sender_is_unverifiable(): void
    {
        $this->assertSame(ProbeResult::UNVERIFIABLE, $this->prober([[554, 'no service']])->probe('a@b.com', ['mx.b.com'])->outcome);
        $this->assertSame(ProbeResult::UNVERIFIABLE, $this->prober([[220, 'hi'], [250, 'hello'], [550, 'sender rejected']])->probe('a@b.com', ['mx.b.com'])->outcome);
    }

    public function test_connection_failure_is_unreachable_and_falls_back_to_the_next_server(): void
    {
        $calls = [];
        $second = new ScriptedSmtpConnection([...$this->greeting(), [250, 'ok'], [550, '5.1.1 no such user']]);
        $p = new SmtpProber('h.test', 'v@h.test', 5, 25, function (string $host) use (&$calls, $second) {
            $calls[] = $host;

            return $host === 'mx1.b.com' ? throw new \RuntimeException('refused') : $second;
        });

        $this->assertSame(ProbeResult::ACCEPTED, $p->probe('a@b.com', ['mx1.b.com', 'mx2.b.com'])->outcome);
        $this->assertSame(['mx1.b.com', 'mx2.b.com'], $calls);

        $down = new SmtpProber('h.test', 'v@h.test', 5, 25, fn () => throw new \RuntimeException('refused'));
        $r = $down->probe('a@b.com', ['mx1.b.com']);
        $this->assertTrue($r->unreachable);
        $this->assertSame(ProbeResult::UNVERIFIABLE, $r->outcome);
    }

    private function validatorWith(SmtpProber $prober): EmailValidator
    {
        config(['mailing.validation.dns' => true, 'mailing.validation.smtp_probe' => true, 'mailing.validation.probe_delay_ms' => 0]);

        return (new EmailValidator)
            ->useResolver(fn () => 'ok')
            ->useMxResolver(fn () => ['mx.example.com'])
            ->useProber($prober);
    }

    public function test_validator_maps_probe_outcomes(): void
    {
        $confirmed = $this->validatorWith($this->prober([...$this->greeting(), [250, 'ok'], [550, 'no such user 5.1.1']]))->validate('a@example.com', true);
        $this->assertSame(['valid', 'mailbox_confirmed'], [$confirmed->status, $confirmed->reason]);

        Cache::flush();
        $gone = $this->validatorWith($this->prober([...$this->greeting(), [550, '5.1.1 user unknown']]))->validate('ghost@example.com', true);
        $this->assertSame(['invalid', 'mailbox_not_found'], [$gone->status, $gone->reason]);

        Cache::flush();
        $unsure = $this->validatorWith($this->prober([...$this->greeting(), [451, 'greylisted try again']]))->validate('b@example.com', true);
        $this->assertSame(['valid', 'unverified'], [$unsure->status, $unsure->reason]);
    }

    public function test_probe_only_runs_when_requested_and_enabled(): void
    {
        $boom = new SmtpProber('h', 'f@h', 1, 25, fn () => throw new \LogicException('must not connect'));
        $v = $this->validatorWith($boom);

        $this->assertNull($v->validate('a@example.com', false)->reason);          // deep not requested: no probe, so no "unverified" note

        config(['mailing.validation.smtp_probe' => false]);
        $this->assertNull($v->validate('a@example.com', true)->reason);            // switched off
    }

    public function test_catch_all_domains_are_remembered_and_not_probed_again(): void
    {
        $v = $this->validatorWith($this->prober([...$this->greeting(), [250, 'ok'], [250, 'ok']]));

        $this->assertSame('catch_all', $v->validate('a@example.com', true)->reason);
        // The script is now exhausted: a second probe would throw. The cached verdict is used instead.
        $this->assertSame('catch_all', $v->validate('b@example.com', true)->reason);
    }

    public function test_many_unreachable_domains_pause_mailbox_checks(): void
    {
        config(['mailing.validation.dns' => true, 'mailing.validation.smtp_probe' => true, 'mailing.validation.probe_delay_ms' => 0]);
        $attempts = 0;
        $v = (new EmailValidator)->useResolver(fn () => 'ok')->useMxResolver(fn () => ['mx'])
            ->useProber(new SmtpProber('h', 'f@h', 1, 25, function () use (&$attempts) {
                $attempts++;

                throw new \RuntimeException('blocked');
            }));

        for ($i = 1; $i <= 12; $i++) {
            $r = $v->validate("a@domain{$i}.example", true);
            $this->assertSame(['valid', 'unverified'], [$r->status, $r->reason]); // never "invalid" because WE could not connect
        }

        $this->assertSame(8, $attempts); // after 8 failures in a row it stops trying
        $this->assertFalse(EmailValidator::probeAvailable());
    }

    public function test_job_and_endpoints_honour_the_probe_setting(): void
    {
        $this->actingAs(User::factory()->create());
        $this->app->instance(EmailValidator::class, $this->validatorWith($this->prober([...$this->greeting(), [550, '5.1.1 user unknown']])));
        $contact = Audience::factory()->create(['email' => 'ghost@example.com']);

        $run = EmailValidationRun::create(['scope' => 'unchecked', 'smtp_probe' => true]);
        ValidateEmailsJob::dispatchSync($run->id);
        $this->assertSame('mailbox_not_found', $contact->fresh()->email_validation_reason);

        config(['mailing.validation.smtp_probe' => false]);
        $this->post('/email-validation', ['scope' => 'all', 'smtp_probe' => 1])->assertSessionHas('error');
        $this->get('/email-validation')->assertOk()->assertSee('Mailbox check');
    }
}
