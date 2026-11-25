<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\RecipientStatus;
use App\Enums\SuppressionReason;
use App\Jobs\SendCampaignBatchJob;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\EmailLog;
use App\Models\SmtpSetting;
use App\Services\Smtp\ProviderHeaders;
use App\Services\Smtp\SendFailure;
use App\Services\Smtp\SmtpErrorClassifier;
use App\Services\Smtp\SmtpMailerFactory;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Sends ONE batch of a campaign. Safety properties:
 *
 *  Idempotency   A recipient is "claimed" with a compare-and-set UPDATE (pending -> sending). Only the worker
 *                whose UPDATE affects a row may send. Duplicate jobs, queue redeliveries and worker restarts
 *                therefore cannot email the same recipient twice. A recipient left in "sending" by a crash has an
 *                unknown outcome and is NEVER auto-resent (see campaigns:recover-stale).
 *  Failures      4xx / unknown errors are retried with back-off up to max_attempts; 5xx and recipient
 *                rejections fail permanently immediately; relay-level problems pause the campaign.
 *  Limits        per-second and per-minute limits are shared across all workers through the Redis-backed
 *                rate limiter (keyed per SMTP setting); the daily cap is counted from email_logs.
 *  Pause/cancel  re-checked before every email; unsent recipients stay pending.
 *  Compliance    suppression / unsubscribe / deletion is re-checked at send time, not only at preparation.
 */
class CampaignSender
{
    /** Stop a batch after this many seconds and hand the rest to a fresh job (queue timeout is 300s). */
    private const SOFT_DEADLINE_SECONDS = 240;

    public function __construct(
        private SmtpMailerFactory $mailers,
        private SmtpErrorClassifier $classifier,
        private TemplateRenderer $renderer,
        private SuppressionService $suppression,
        private CampaignStatsService $stats,
        private CampaignService $campaigns,
    ) {}

    /** @param array<int> $recipientIds */
    public function sendBatch(int $campaignId, array $recipientIds): void
    {
        $campaign = Campaign::query()->with('template')->find($campaignId);

        if (! $campaign || $campaign->status !== CampaignStatus::Sending) {
            return; // paused, cancelled, trashed or finished: do nothing, leave recipients pending
        }

        $smtp = $this->campaigns->resolveSmtp($campaign);

        if (! $smtp) {
            $this->campaigns->pauseAutomatically($campaign, 'No active SMTP configuration');

            return;
        }

        $limits = SendingLimits::for($campaign, $smtp);
        $mailer = $this->mailers->make($smtp);
        $html = $campaign->html_snapshot ?: (string) $campaign->template?->html_content;
        $subjectTemplate = $campaign->subject_snapshot ?: $campaign->subject;

        $recipients = CampaignRecipient::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('id', $recipientIds)
            ->where('status', RecipientStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->values();

        $audiences = Audience::withTrashed()
            ->whereIn('id', $recipients->pluck('audience_id')->filter()->all())
            ->get(['id', 'status', 'subscription_status', 'deleted_at'])
            ->keyBy('id');
        $suppressed = $this->suppression->suppressedAmong($recipients->pluck('email'));

        $sentToday = $limits->dailyLimit !== null ? $this->sentToday($smtp) : 0;
        $deadline = microtime(true) + self::SOFT_DEADLINE_SECONDS;

        /** @var array<int> $retryIds  @var array<int> $notDueIds */
        $retryIds = [];
        $notDueIds = [];
        $minNotDue = null;

        foreach ($recipients as $index => $recipient) {
            if (! $this->campaignStillSending($campaignId)) {
                break;
            }

            // Re-check eligibility at send time: contact deleted / unsubscribed / suppressed since preparation.
            if ($reason = $this->ineligibleReason($recipient, $audiences, $suppressed)) {
                $this->markSkipped($recipient, $reason);

                continue;
            }

            if ($recipient->next_retry_at && $recipient->next_retry_at->isFuture()) {
                $notDueIds[] = $recipient->id;
                $minNotDue = $minNotDue ? min($minNotDue, $recipient->next_retry_at->getTimestamp()) : $recipient->next_retry_at->getTimestamp();

                continue;
            }

            if ($limits->dailyLimit !== null && $sentToday >= $limits->dailyLimit) {
                $this->redispatch($campaignId, $recipients->slice($index), (int) ceil(now()->diffInSeconds(now()->addDay()->startOfDay()->addMinutes(5), true)));

                break;
            }

            if (microtime(true) > $deadline) {
                $this->redispatch($campaignId, $recipients->slice($index), 1);

                break;
            }

            if (($wait = $this->acquireSendSlot($smtp, $limits)) > 0) {
                $this->redispatch($campaignId, $recipients->slice($index), $wait);

                break;
            }

            // ---- claim (the idempotency gate) ----
            $claimed = CampaignRecipient::query()
                ->whereKey($recipient->id)
                ->where('status', RecipientStatus::Pending->value)
                ->update([
                    'status' => RecipientStatus::Sending->value,
                    'attempts' => DB::raw('attempts + 1'),
                    'last_attempt_at' => now(),
                    'next_retry_at' => null,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                continue; // another worker owns it
            }

            $recipient->attempts++;

            $sent = null;

            try {
                $messageId = $this->deliver($mailer, $campaign, $smtp, $recipient, $html, $subjectTemplate, $sent);
            } catch (Throwable $e) {
                $failure = $this->classifier->classify($e);

                if ($failure->kind === SendFailure::SYSTEMIC) {
                    // Relay problem, not the recipient's fault: put the recipient back untouched and stop.
                    CampaignRecipient::whereKey($recipient->id)->update([
                        'status' => RecipientStatus::Pending->value,
                        'attempts' => DB::raw('CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END'),
                    ]);
                    $this->log($campaign, $recipient, $smtp, 'deferred', null, $failure);
                    $this->campaigns->pauseAutomatically($campaign, 'SMTP problem: '.$failure->message);

                    break;
                }

                if ($failure->kind === SendFailure::TEMPORARY && $recipient->attempts < $limits->maxAttempts) {
                    $delay = $limits->retryDelay * $recipient->attempts; // linear back-off
                    CampaignRecipient::whereKey($recipient->id)->update([
                        'status' => RecipientStatus::Pending->value,
                        'next_retry_at' => now()->addSeconds($delay),
                        'failure_reason' => $failure->message,
                        'failure_type' => SendFailure::TEMPORARY,
                    ]);
                    $this->log($campaign, $recipient, $smtp, 'failed', null, $failure);
                    $retryIds[$delay][] = $recipient->id;

                    continue;
                }

                // Permanent, or temporary but out of attempts: final. Never retried automatically.
                CampaignRecipient::whereKey($recipient->id)->update([
                    'status' => RecipientStatus::Failed->value,
                    'failure_reason' => $failure->message,
                    'failure_type' => $failure->kind === SendFailure::PERMANENT ? SendFailure::PERMANENT : SendFailure::TEMPORARY,
                ]);
                $this->log($campaign, $recipient, $smtp, 'failed', null, $failure, permanent: true);

                if ($failure->suppressRecipient) {
                    $this->suppression->add($recipient->email, SuppressionReason::Bounced, 'smtp_rejection', $campaign->id, $failure->message);
                }

                continue;
            }

            // ---- accepted by the relay ----
            try {
                CampaignRecipient::whereKey($recipient->id)->update([
                    // A fast "delivered"/"bounced" webhook may already have landed; never downgrade it.
                    'status' => DB::raw("CASE WHEN delivered_at IS NOT NULL THEN 'delivered' WHEN bounced_at IS NOT NULL THEN 'bounced' ELSE 'sent' END"),
                    'sent_at' => now(),
                    'message_id' => $messageId,
                    'failure_reason' => null,
                    'failure_type' => null,
                    'updated_at' => now(),
                ]);
                $this->log($campaign, $recipient, $smtp, 'sent', $messageId, null, response: $this->summarise($sent));
                $sentToday++;
            } catch (Throwable $e) {
                // The mail WAS sent; failing to record it must not trigger a resend. The row stays "sending".
                Log::critical('Email sent but could not be recorded', ['recipient_id' => $recipient->id, 'error' => $e->getMessage()]);
            }
        }

        // Retries run later in their own jobs (grouped by delay) so a flaky recipient never blocks the batch.
        foreach ($retryIds as $delay => $ids) {
            SendCampaignBatchJob::dispatch($campaignId, $ids)->delay(now()->addSeconds((int) $delay));
        }

        if ($notDueIds) {
            SendCampaignBatchJob::dispatch($campaignId, $notDueIds)->delay(now()->addSeconds(max(5, ($minNotDue ?? time()) - time())));
        }

        $this->stats->refresh($campaignId);
        $this->campaigns->finalizeIfDone($campaign->refresh());
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  SentMessage|null  $sent  (out) the transport result
     * @return string the Message-ID we assigned
     */
    private function deliver($mailer, Campaign $campaign, SmtpSetting $smtp, CampaignRecipient $r, string $html, string $subjectTemplate, ?SentMessage &$sent = null): string
    {
        $messageId = sprintf('c%d.r%d.%s@%s', $campaign->id, $r->id, bin2hex(random_bytes(6)), $this->messageDomain($campaign));
        $body = $this->renderer->render($html, $r);
        $plain = $this->renderer->toPlainText($body);
        $subject = $this->renderer->subject($subjectTemplate, $r);
        $unsubscribeUrl = route('unsubscribe.show', ['token' => $r->tracking_token]);

        $sent = $mailer->html($body, function (Message $m) use ($campaign, $smtp, $r, $messageId, $plain, $subject, $unsubscribeUrl) {
            $m->to($r->email, $r->name ?: null)
                ->from($campaign->from_email, $campaign->from_name)
                ->subject($subject);

            if ($campaign->reply_to_email) {
                $m->replyTo($campaign->reply_to_email);
            }

            $symfony = $m->getSymfonyMessage();
            $headers = $symfony->getHeaders();
            $headers->addIdHeader('Message-ID', $messageId);
            // RFC 8058 one-click unsubscribe (required by Gmail/Yahoo for bulk senders).
            $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $headers->addTextHeader('X-Campaign-ID', (string) $campaign->id);
            $headers->addTextHeader('Precedence', 'bulk');
            ProviderHeaders::apply($symfony, $smtp->provider, $r->tracking_token, $campaign->id);

            $symfony->text($plain);
        });

        return $messageId;
    }

    private function messageDomain(Campaign $campaign): string
    {
        $domain = substr(strrchr($campaign->from_email, '@') ?: '@localhost', 1);

        return preg_match('/^[a-z0-9.-]+$/i', $domain) ? $domain : 'localhost';
    }

    /** @param Collection<int, Audience> $audiences @param array<string, true> $suppressed */
    private function ineligibleReason(CampaignRecipient $r, Collection $audiences, array $suppressed): ?string
    {
        if (isset($suppressed[$r->email])) {
            return 'Address is on the suppression list';
        }

        // audience_id is nulled only if the contact row itself was removed.
        $a = $r->audience_id ? $audiences->get($r->audience_id) : null;

        if (! $a || $a->trashed()) {
            return 'Contact was deleted';
        }

        if ($a->status !== 'active' || $a->subscription_status !== 'subscribed') {
            return 'Contact is inactive or unsubscribed';
        }

        return null;
    }

    private function markSkipped(CampaignRecipient $r, string $reason): void
    {
        CampaignRecipient::whereKey($r->id)->where('status', RecipientStatus::Pending->value)->update([
            'status' => RecipientStatus::Skipped->value,
            'failure_reason' => $reason,
        ]);
    }

    private function campaignStillSending(int $campaignId): bool
    {
        return Campaign::query()->whereKey($campaignId)->value('status') === CampaignStatus::Sending; // value() applies the enum cast
    }

    private function sentToday(SmtpSetting $smtp): int
    {
        return EmailLog::query()
            ->where('smtp_setting_id', $smtp->id)
            ->where('status', 'sent')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /** @return int 0 when a slot was taken, otherwise the seconds to wait before trying again */
    private function acquireSendSlot(SmtpSetting $smtp, SendingLimits $limits): int
    {
        $minuteKey = "smtp:{$smtp->id}:minute";
        $secondKey = "smtp:{$smtp->id}:second";

        if (RateLimiter::tooManyAttempts($minuteKey, $limits->perMinute)) {
            return max(1, RateLimiter::availableIn($minuteKey));
        }

        $spins = 0;
        while (RateLimiter::tooManyAttempts($secondKey, $limits->perSecond)) {
            usleep(100_000);

            if (++$spins > 30) {
                return 2;
            }
        }

        RateLimiter::hit($secondKey, 1);
        RateLimiter::hit($minuteKey, 60);

        return 0;
    }

    /** @param Collection<int, CampaignRecipient> $remaining */
    private function redispatch(int $campaignId, Collection $remaining, int $delaySeconds): void
    {
        $ids = $remaining->pluck('id')->all();

        if ($ids) {
            SendCampaignBatchJob::dispatch($campaignId, $ids)->delay(now()->addSeconds(max(1, $delaySeconds)));
        }
    }

    private function log(Campaign $c, CampaignRecipient $r, SmtpSetting $smtp, string $status, ?string $messageId, ?SendFailure $failure, bool $permanent = false, ?string $response = null): void
    {
        EmailLog::create([
            'campaign_id' => $c->id,
            'campaign_recipient_id' => $r->id,
            'smtp_setting_id' => $smtp->id,
            'attempt' => $r->attempts,
            'status' => $status,
            'message_id' => $messageId,
            'smtp_code' => $failure?->code,
            'response' => $failure?->message ?? $response,
            'is_permanent' => $permanent || ($failure?->kind === SendFailure::PERMANENT),
        ]);
    }

    /** Keep only the final "250 ... queued as X" style line of the SMTP transcript. */
    private function summarise(?SentMessage $sent): ?string
    {
        $debug = $sent?->getSymfonySentMessage()?->getDebug();

        if (! $debug || ! preg_match_all('/^< (250[^\r\n]*)$/m', $debug, $m)) {
            return null;
        }

        return $this->classifier->sanitize((string) end($m[1]));
    }
}
