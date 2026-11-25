<?php

namespace App\Jobs;

use App\Services\CampaignSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCampaignBatchJob implements ShouldQueue
{
    use Queueable;

    /** Infrastructure retries only (worker killed, DB hiccup). Recipient-level retries are handled by the sender. */
    public int $tries = 3;

    /** Must stay below REDIS_QUEUE_RETRY_AFTER (420) so a running job is never redelivered. */
    public int $timeout = 300;

    public int $maxExceptions = 2;

    /** @param array<int> $recipientIds */
    public function __construct(public int $campaignId, public array $recipientIds)
    {
        $this->onQueue(config('mailing.queues.emails'));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(CampaignSender $sender): void
    {
        $sender->sendBatch($this->campaignId, $this->recipientIds);
    }

    public function failed(Throwable $e): void
    {
        // Unclaimed recipients stay "pending" and are picked up by resume; claimed ones are never auto-resent.
        Log::error('SendCampaignBatchJob failed', [
            'campaign_id' => $this->campaignId,
            'recipients' => count($this->recipientIds),
            'error' => $e->getMessage(),
        ]);
    }
}
