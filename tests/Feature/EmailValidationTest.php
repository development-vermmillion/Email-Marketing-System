<?php

namespace Tests\Feature;

use App\Jobs\ValidateEmailsJob;
use App\Models\Audience;
use App\Models\AudienceGroup;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailValidationRun;
use App\Models\User;
use App\Services\EmailValidator;
use App\Services\RecipientPreparer;
use App\Support\ValidationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailValidationTest extends TestCase
{
    use RefreshDatabase;

    /** A validator whose DNS answers come from a fixed table instead of the network. */
    private function validator(array $domains = []): EmailValidator
    {
        config(['mailing.validation.dns' => true]);

        return (new EmailValidator)->useResolver(fn (string $d) => $domains[$d] ?? 'none');
    }

    public function test_valid_address_with_working_domain(): void
    {
        $r = $this->validator(['example.com' => 'ok'])->validate('Jane.Doe@Example.com ');

        $this->assertSame(ValidationResult::VALID, $r->status);
        $this->assertNull($r->reason);
    }

    public function test_bad_syntax_is_invalid(): void
    {
        $v = $this->validator(['example.com' => 'ok']);

        foreach (['', 'plainaddress', 'a@b', 'a..b@example.com', '@example.com', 'a b@example.com', str_repeat('x', 65).'@example.com'] as $bad) {
            $r = $v->validate($bad);
            $this->assertSame([ValidationResult::INVALID, 'syntax'], [$r->status, $r->reason], "Expected '{$bad}' to fail on syntax");
        }
    }

    public function test_domain_without_mail_server_is_invalid(): void
    {
        $this->assertSame('no_mail_server', $this->validator()->validate('a@no-such-domain-xyz.com')->reason);
        $this->assertSame('null_mx', $this->validator(['nomail.com' => 'null_mx'])->validate('a@nomail.com')->reason);
    }

    public function test_disposable_role_and_typo_are_risky(): void
    {
        $v = $this->validator(['mailinator.com' => 'ok', 'example.com' => 'ok', 'gmial.com' => 'ok']);

        $this->assertSame(['risky', 'disposable'], [$v->validate('x@mailinator.com')->status, $v->validate('x@mailinator.com')->reason]);
        $this->assertSame('risky', $v->validate('info@example.com')->status);
        $this->assertSame('role', $v->validate('Support@example.com')->reason);

        $typo = $v->validate('sam@gmial.com');
        $this->assertSame(['risky', 'typo', 'sam@gmail.com'], [$typo->status, $typo->reason, $typo->suggestion]);
    }

    public function test_dns_checks_can_be_switched_off(): void
    {
        config(['mailing.validation.dns' => false]);
        $v = (new EmailValidator)->useResolver(fn () => throw new \RuntimeException('DNS must not be queried'));

        $this->assertSame('valid', $v->validate('a@anything-at-all.test')->status);
    }

    public function test_job_validates_contacts_in_scope_and_records_counts(): void
    {
        $this->app->instance(EmailValidator::class, $this->validator(['example.com' => 'ok']));

        $good = Audience::factory()->create(['email' => 'good@example.com']);
        $bad = Audience::factory()->create(['email' => 'bad@dead-domain.test']);
        $risky = Audience::factory()->create(['email' => 'info@example.com']);
        $done = Audience::factory()->validationStatus('valid')->create(['email' => 'done@example.com']);

        $run = EmailValidationRun::create(['scope' => 'unchecked']);
        ValidateEmailsJob::dispatchSync($run->id);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame([3, 1, 1, 1], [$run->processed, $run->valid_count, $run->risky_count, $run->invalid_count]);
        $this->assertSame('valid', $good->fresh()->email_validation_status);
        $this->assertSame('no_mail_server', $bad->fresh()->email_validation_reason);
        $this->assertSame('risky', $risky->fresh()->email_validation_status);
        $this->assertSame('active', $bad->fresh()->status);            // not deactivated unless asked
        $this->assertSame('valid', $done->fresh()->email_validation_status); // already checked: untouched
    }

    public function test_job_can_deactivate_invalid_contacts_and_respects_group_scope(): void
    {
        $this->app->instance(EmailValidator::class, $this->validator());

        $group = AudienceGroup::factory()->create();
        $inGroup = Audience::factory()->create(['email' => 'a@dead.test']);
        $inGroup->groups()->attach($group->id);
        $outside = Audience::factory()->create(['email' => 'b@dead.test']);

        $run = EmailValidationRun::create(['scope' => 'group', 'group_id' => $group->id, 'deactivate_invalid' => true]);
        ValidateEmailsJob::dispatchSync($run->id);

        $this->assertSame('inactive', $inGroup->fresh()->status);
        $this->assertNull($outside->fresh()->email_validation_status);
    }

    public function test_cancelled_run_is_not_processed(): void
    {
        $this->app->instance(EmailValidator::class, $this->validator());
        Audience::factory()->create();
        $run = EmailValidationRun::create(['scope' => 'all', 'status' => 'cancelled']);

        ValidateEmailsJob::dispatchSync($run->id);

        $this->assertSame(0, $run->fresh()->processed);
    }

    public function test_invalid_contacts_are_left_out_of_campaigns(): void
    {
        Audience::factory()->create(['email' => 'ok@example.com']);
        Audience::factory()->create(['email' => 'risky@example.com', 'email_validation_status' => 'risky', 'email_validation_reason' => 'role']);
        Audience::factory()->validationStatus('invalid', 'no_mail_server')->create(['email' => 'bad@example.com']);

        $campaign = Campaign::factory()->create();
        app(RecipientPreparer::class)->prepare($campaign);

        $emails = CampaignRecipient::where('campaign_id', $campaign->id)->orderBy('email')->pluck('email')->all();
        $this->assertSame(['ok@example.com', 'risky@example.com'], $emails);

        config(['mailing.validation.skip_invalid' => false]);
        $this->assertSame(3, app(RecipientPreparer::class)->estimate(null));
    }

    public function test_changing_a_contacts_email_resets_its_validation(): void
    {
        $contact = Audience::factory()->validationStatus('invalid', 'syntax')->create();

        $contact->update(['email' => 'someone.new@example.com']);

        $this->assertNull($contact->fresh()->email_validation_status);
    }

    public function test_pages_and_endpoints_require_admin_and_work(): void
    {
        $this->get('/email-validation')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        $this->app->instance(EmailValidator::class, $this->validator(['example.com' => 'ok']));

        $this->get('/email-validation')->assertOk();
        $this->postJson('/email-validation/check', ['email' => 'jane@example.com'])->assertOk()->assertJson(['status' => 'valid']);
        $this->postJson('/email-validation/check', ['email' => 'nope'])->assertOk()->assertJson(['status' => 'invalid', 'reason' => 'syntax']);
        $this->postJson('/email-validation/check', [])->assertStatus(422);

        Audience::factory()->create(['email' => 'one@example.com']);
        $this->post('/email-validation', ['scope' => 'unchecked'])->assertRedirect();

        $run = EmailValidationRun::firstOrFail();
        $this->assertSame('completed', $run->status); // queue is "sync" in tests
        $this->get("/email-validation/{$run->id}")->assertOk();
        $this->getJson("/email-validation/{$run->id}/progress")->assertOk()->assertJson(['finished' => true, 'valid' => 1]);
        $this->get('/audiences?validation=valid')->assertOk()->assertSee('one@example.com');
    }

    public function test_group_scope_requires_a_group_and_runs_do_not_overlap(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/email-validation', ['scope' => 'group'])->assertSessionHasErrors('group_id');

        EmailValidationRun::create(['scope' => 'all', 'status' => 'processing']);
        $this->post('/email-validation', ['scope' => 'all'])->assertSessionHas('error');
        $this->assertSame(1, EmailValidationRun::count());
    }

    public function test_export_and_deactivate(): void
    {
        $this->actingAs(User::factory()->create());
        Audience::factory()->validationStatus('invalid', 'syntax')->create(['email' => 'bad@example.com', 'name' => '=cmd()']);
        Audience::factory()->validationStatus('valid')->create(['email' => 'fine@example.com']);

        $csv = $this->get('/email-validation/export?status=invalid')->assertOk()->streamedContent();
        $this->assertStringContainsString('bad@example.com', $csv);
        $this->assertStringNotContainsString('fine@example.com', $csv);
        $this->assertStringContainsString("'=cmd()", $csv); // formula neutralised
        $this->get('/email-validation/export?status=bogus')->assertNotFound();

        $this->post('/email-validation/deactivate-invalid')->assertRedirect();
        $this->assertSame('inactive', Audience::where('email', 'bad@example.com')->value('status'));
        $this->assertSame('active', Audience::where('email', 'fine@example.com')->value('status'));
    }
}
