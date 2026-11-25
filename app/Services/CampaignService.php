<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Events\CampaignStatusChanged;
use App\Jobs\SendCampaignBatchJob;
use App\Jobs\StartCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailEvent;
use App\Models\EmailLog;
use App\Models\SmtpSetting;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class CampaignService
{
    public function __construct(
        private RecipientPreparer $preparer,
        private CampaignStatsService $stats,
        private ActivityLogger $activity,
    ) {}

    /* ---------------------------------------------------------------- CRUD */

    public function create(array $data, ?int $userId): Campaign
    {
        $campaign = Campaign::create($this->fill($data) + ['created_by' => $userId]);
        $campaign->forceFill(['status' => CampaignStatus::Draft])->save();

        $this->activity->log('campaign', 'created', "Campaign \"{$campaign->name}\" created", $campaign);

        return $campaign;
    }

    public function update(Campaign $campaign, array $data): Campaign
    {
        // Recipients and content are frozen as soon as sending is queued: changing them mid-send would
        // make reports meaningless and could email people who were never selected.
        if (! $campaign->canEdit()) {
            throw new DomainException('This campaign can no longer be edited because sending has started or it is in the trash.');
        }

        $campaign->update($this->fill($data));
        $this->activity->log('campaign', 'updated', "Campaign \"{$campaign->name}\" updated", $campaign);

        return $campaign;
    }

    /** @return array<string, mixed> */
    private function fill(array $data): array
    {
        $allowed = [
            'name', 'subject', 'from_name', 'from_email', 'reply_to_email', 'email_template_id', 'smtp_setting_id',
            'group_ids', 'scheduled_at', 'rate_per_second', 'rate_per_minute', 'batch_size', 'max_attempts',
            'retry_delay', 'daily_limit',
        ];

        $out = array_intersect_key($data, array_flip($allowed));

        // Unchecked checkboxes are not submitted at all: a missing key means "no groups selected" (= everyone eligible),
        // so un-ticking every group on an existing campaign must clear the stored selection.
        $out['group_ids'] = array_values(array_unique(array_map('intval', (array) ($out['group_ids'] ?? []))));

        return $out;
    }

    public function duplicate(Campaign $source, ?int $userId): Campaign
    {
        $copy = Campaign::create([
            'name' => 'Copy of '.$source->name,
            'subject' => $source->subject,
            'from_name' => $source->from_name,
            'from_email' => $source->from_email,
            'reply_to_email' => $source->reply_to_email,
            'email_template_id' => $source->email_template_id,
            'smtp_setting_id' => $source->smtp_setting_id,
            'group_ids' => $source->group_ids,
            'rate_per_second' => $source->rate_per_second,
            'rate_per_minute' => $source->rate_per_minute,
            'batch_size' => $source->batch_size,
            'max_attempts' => $source->max_attempts,
            'retry_delay' => $source->retry_delay,
            'daily_limit' => $source->daily_limit,
            'created_by' => $userId,
        ]);
        $copy->forceFill(['status' => CampaignStatus::Draft])->save();

        $this->activity->log('campaign', 'duplicated', "Duplicated from campaign #{$source->id}", $copy);

        return $copy;
    }

    /* ------------------------------------------------------------ Lifecycle */

    public function sendNow(Campaign $campaign): void
    {
        $this->guardReadyToSend($campaign);

        $moved = $this->moveStatus($campaign, [CampaignStatus::Draft, CampaignStatus::Scheduled], CampaignStatus::Queued, [
            'scheduled_at' => $campaign->scheduled_at,
            // Freeze what will be sent.
            'html_snapshot' => $campaign->template->html_content,
            'subject_snapshot' => $campaign->subject,
        ]);

        if (! $moved) {
            throw new DomainException('Only draft or scheduled campaigns can be sent.');
        }

        StartCampaignJob::dispatch($campaign->id);
    }

    public function schedule(Campaign $campaign, CarbonInterface $at): void
    {
        if (! $campaign->status->isEditable()) {
            throw new DomainException('Only draft or scheduled campaigns can be scheduled.');
        }

        if ($at->isPast()) {
            throw new DomainException('The scheduled time must be in the future.');
        }

        $this->guardReadyToSend($campaign);

        $campaign->forceFill(['scheduled_at' => $at])->save();
        $this->moveStatus($campaign, [CampaignStatus::Draft, CampaignStatus::Scheduled], CampaignStatus::Scheduled);
    }

    public function unschedule(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::Scheduled) {
            throw new DomainException('Campaign is not scheduled.');
        }

        $campaign->forceFill(['scheduled_at' => null])->save();
        $this->moveStatus($campaign, [CampaignStatus::Scheduled], CampaignStatus::Draft);
    }

    /** Called by the scheduler every minute. */
    public function dispatchDue(): int
    {
        $count = 0;

        Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get()
            ->each(function (Campaign $campaign) use (&$count) {
                try {
                    $this->sendNow($campaign);
                    $count++;
                } catch (DomainException $e) {
                    $this->moveStatus($campaign, [CampaignStatus::Scheduled], CampaignStatus::Failed, [], 'Could not start: '.$e->getMessage());
                }
            });

        return $count;
    }

    public function pause(Campaign $campaign): void
    {
        if (! $this->moveStatus($campaign, [CampaignStatus::Queued, CampaignStatus::Sending], CampaignStatus::Paused, ['paused_at' => now()])) {
            throw new DomainException('Only a queued or sending campaign can be paused.');
        }
    }

    /** Pause triggered by the engine itself (bad credentials, relay unreachable...). */
    public function pauseAutomatically(Campaign $campaign, string $reason): void
    {
        $this->moveStatus($campaign, [CampaignStatus::Queued, CampaignStatus::Sending], CampaignStatus::Paused, ['paused_at' => now()], $reason);
    }

    public function resume(Campaign $campaign): void
    {
        $hasRecipients = CampaignRecipient::where('campaign_id', $campaign->id)->exists();

        $target = $hasRecipients ? CampaignStatus::Sending : CampaignStatus::Queued;

        if (! $this->moveStatus($campaign, [CampaignStatus::Paused], $target, ['paused_at' => null])) {
            throw new DomainException('Only a paused campaign can be resumed.');
        }

        // Existing progress is preserved: only recipients that are still pending are dispatched again.
        $hasRecipients ? $this->dispatchBatches($campaign) : StartCampaignJob::dispatch($campaign->id);
    }

    public function cancel(Campaign $campaign): void
    {
        $moved = $this->moveStatus(
            $campaign,
            [CampaignStatus::Scheduled, CampaignStatus::Queued, CampaignStatus::Sending, CampaignStatus::Paused],
            CampaignStatus::Cancelled,
            ['cancelled_at' => now()]
        );

        if (! $moved) {
            throw new DomainException('This campaign cannot be cancelled in its current state.');
        }

        CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::Pending->value)
            ->update(['status' => RecipientStatus::Skipped->value, 'failure_reason' => 'Campaign cancelled']);

        $this->stats->refresh($campaign);
    }

    /** Engine entry point after StartCampaignJob has created recipients. */
    public function begin(Campaign $campaign): void
    {
        $total = $this->preparer->prepare($campaign);

        // Atomic Queued -> Sending flip: if two workers race, only one dispatches batches.
        $started = $this->moveStatus($campaign, [CampaignStatus::Queued], CampaignStatus::Sending, ['started_at' => now()]);

        $this->stats->refresh($campaign);

        if (! $started) {
            return; // paused/cancelled while recipients were being built
        }

        if ($total === 0) {
            $this->complete($campaign->refresh(), 'No eligible recipients');

            return;
        }

        $this->dispatchBatches($campaign);
    }

    public function dispatchBatches(Campaign $campaign): void
    {
        $size = SendingLimits::for($campaign, $this->resolveSmtp($campaign))->batchSize;

        CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', RecipientStatus::Pending->value)
            ->select('id')
            ->chunkById($size, fn ($chunk) => SendCampaignBatchJob::dispatch($campaign->id, $chunk->pluck('id')->all()));
    }

    /** Marks the campaign completed (or failed when nothing at all could be sent). Safe to call repeatedly. */
    public function finalizeIfDone(Campaign $campaign): void
    {
        $inFlight = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', [RecipientStatus::Pending->value, RecipientStatus::Sending->value])
            ->exists();

        if (! $inFlight) {
            $this->complete($campaign);
        }
    }

    private function complete(Campaign $campaign, ?string $note = null): void
    {
        $this->stats->refresh($campaign);
        $campaign->refresh();

        $to = ($campaign->sent_count === 0 && $campaign->failed_count > 0)
            ? CampaignStatus::Failed
            : CampaignStatus::Completed;

        $this->moveStatus($campaign, [CampaignStatus::Sending], $to, ['completed_at' => now()], $note);
    }

    /* ---------------------------------------------------------------- Trash */

    public function trash(Campaign $campaign): void
    {
        if ($campaign->status->isActive()) {
            throw new DomainException('Cancel or finish the campaign before deleting it.');
        }

        DB::transaction(function () use ($campaign) {
            $campaign->forceFill(['status_before_trash' => $campaign->status->value])->save();
            $this->moveStatus($campaign, [$campaign->status], CampaignStatus::Trashed);
            $campaign->delete();
        });
    }

    public function restore(Campaign $campaign): void
    {
        $previous = CampaignStatus::tryFrom((string) $campaign->status_before_trash) ?? CampaignStatus::Draft;

        // A campaign that was waiting for a schedule must not fire unexpectedly after a restore.
        if ($previous === CampaignStatus::Scheduled) {
            $previous = CampaignStatus::Draft;
        }

        $campaign->restore();
        $this->moveStatus($campaign, [CampaignStatus::Trashed], $previous, ['status_before_trash' => null]);
    }

    /**
     * Permanently removes campaigns trashed longer than the retention period. The final totals are written
     * to the audit log first, so a record of the campaign's results outlives its detailed rows.
     * Suppression records are intentionally untouched (no FK to campaigns).
     */
    public function purgeExpired(): int
    {
        $cutoff = now()->subDays((int) config('mailing.trash_retention_days', 7));
        $purged = 0;

        Campaign::onlyTrashed()->where('deleted_at', '<=', $cutoff)->each(function (Campaign $campaign) use (&$purged) {
            $this->stats->refresh($campaign);
            $campaign->refresh();

            $this->activity->log('campaign', 'purged', "Campaign \"{$campaign->name}\" permanently deleted after trash retention", null, [
                'campaign_id' => $campaign->id,
                'name' => $campaign->name,
                'total_recipients' => $campaign->total_recipients,
                'sent' => $campaign->sent_count,
                'delivered' => $campaign->delivered_count,
                'opened' => $campaign->opened_count,
                'clicked' => $campaign->clicked_count,
                'bounced' => $campaign->bounced_count,
                'failed' => $campaign->failed_count,
                'unsubscribed' => $campaign->unsubscribed_count,
            ]);

            // Delete children in chunks so one huge campaign does not hold a long table lock.
            foreach ([EmailEvent::class, EmailLog::class, CampaignRecipient::class] as $model) {
                do {
                    $deleted = $model::query()->where('campaign_id', $campaign->id)->limit(5000)->delete();
                } while ($deleted > 0);
            }

            $campaign->forceDelete();
            $purged++;
        });

        return $purged;
    }

    /* -------------------------------------------------------------- Helpers */

    public function resolveSmtp(Campaign $campaign): ?SmtpSetting
    {
        if ($campaign->smtp_setting_id) {
            $chosen = SmtpSetting::query()->where('is_active', true)->find($campaign->smtp_setting_id);

            if ($chosen) {
                return $chosen;
            }
        }

        return SmtpSetting::resolveDefault();
    }

    private function guardReadyToSend(Campaign $campaign): void
    {
        if (! $this->resolveSmtp($campaign)) {
            throw new DomainException('No active SMTP configuration. Add and enable one under SMTP Settings first.');
        }

        if ($this->preparer->estimate($campaign->group_ids) === 0) {
            throw new DomainException('The selected audience has no contacts that can receive email (all unsubscribed, suppressed or empty).');
        }
    }

    /**
     * Compare-and-set status transition. Returns false when the campaign was not in one of the expected
     * states, which makes pause/resume/cancel/complete safe against double clicks and racing workers.
     *
     * @param  list<CampaignStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    public function moveStatus(Campaign $campaign, array $from, CampaignStatus $to, array $extra = [], ?string $note = null): bool
    {
        $before = Campaign::withTrashed()->whereKey($campaign->id)->value('status');

        $updated = Campaign::withTrashed()
            ->whereKey($campaign->id)
            ->whereIn('status', array_map(fn (CampaignStatus $s) => $s->value, $from))
            ->update($extra + ['status' => $to->value, 'status_note' => $note, 'updated_at' => now()]);

        if ($updated === 0) {
            return false;
        }

        $campaign->refresh();
        event(new CampaignStatusChanged($campaign, CampaignStatus::tryFrom((string) $before), $to, $note));

        return true;
    }
}
