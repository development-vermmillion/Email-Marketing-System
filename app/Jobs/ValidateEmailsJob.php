<?php

namespace App\Jobs;

use App\Models\Audience;
use App\Models\EmailValidationRun;
use App\Services\ActivityLogger;
use App\Services\EmailValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Validates contacts in id order. It works for a bounded time slice, saves a checkpoint (last_id) and queues itself
 * again, so a 100,000-contact run never needs a long-lived job and a worker restart resumes where it left off.
 */
class ValidateEmailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    private const SLICE_SECONDS = 150;

    public function __construct(public int $runId)
    {
        $this->onQueue(config('mailing.queues.imports'));
    }

    public function handle(EmailValidator $validator, ActivityLogger $activity): void
    {
        $run = EmailValidationRun::find($this->runId);

        if (! $run || ! $run->isActive()) {
            return; // cancelled, finished or deleted
        }

        if ($run->status === 'pending') {
            $run->update(['status' => 'processing', 'started_at' => now(), 'total' => $this->scope($run)->count()]);
        }

        $deadline = microtime(true) + self::SLICE_SECONDS;
        $deep = $run->smtp_probe && EmailValidator::probeAvailable();
        // Mailbox checks take seconds per address, so work in small batches and check the clock between contacts.
        $chunk = $deep ? 25 : max(1, (int) config('mailing.validation.chunk_size', 200));

        while (microtime(true) < $deadline) {
            if (EmailValidationRun::whereKey($run->id)->value('status') === 'cancelled') {
                return;
            }

            $contacts = $this->scope($run)->where('id', '>', $run->last_id)->orderBy('id')->limit($chunk)->get(['id', 'email', 'status']);

            if ($contacts->isEmpty()) {
                $this->finish($run, $activity);

                return;
            }

            $counts = ['valid' => 0, 'risky' => 0, 'invalid' => 0];
            $deactivate = [];
            $done = 0;
            $lastId = $run->last_id;

            foreach ($contacts as $contact) {
                if ($done > 0 && microtime(true) >= $deadline) {
                    break; // checkpoint below records exactly how far we got
                }

                $result = $validator->validate($contact->email, $deep);
                $counts[$result->status]++;
                $done++;
                $lastId = $contact->id;

                DB::table('audiences')->where('id', $contact->id)->update([
                    'email_validation_status' => $result->status,
                    'email_validation_reason' => $result->reason,
                    'email_validated_at' => now(),
                ]);

                if ($result->status === 'invalid' && $run->deactivate_invalid && $contact->status === 'active') {
                    $deactivate[] = $contact->id;
                }
            }

            if ($deactivate) {
                DB::table('audiences')->whereIn('id', $deactivate)->update(['status' => 'inactive']);
            }

            $run->forceFill([
                'last_id' => $lastId,
                'processed' => $run->processed + $done,
                'valid_count' => $run->valid_count + $counts['valid'],
                'risky_count' => $run->risky_count + $counts['risky'],
                'invalid_count' => $run->invalid_count + $counts['invalid'],
            ])->save();
        }

        // Time slice used up: continue in a fresh job.
        self::dispatch($run->id);
    }

    public function failed(Throwable $e): void
    {
        EmailValidationRun::whereKey($this->runId)->whereIn('status', ['pending', 'processing'])->update([
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 500),
            'finished_at' => now(),
        ]);
    }

    private function scope(EmailValidationRun $run): Builder
    {
        return Audience::query()
            ->when($run->scope === 'unchecked', fn (Builder $q) => $q->whereNull('email_validation_status'))
            ->when($run->scope === 'group', fn (Builder $q) => $q->whereHas('groups', fn (Builder $g) => $g->where('audience_groups.id', $run->group_id)));
    }

    private function finish(EmailValidationRun $run, ActivityLogger $activity): void
    {
        $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

        $activity->log('audience', 'emails_validated', sprintf(
            'Email validation finished: %d valid, %d risky, %d invalid.', $run->valid_count, $run->risky_count, $run->invalid_count
        ), null, ['run_id' => $run->id]);
    }
}
