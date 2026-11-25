<?php

namespace App\Jobs;

use App\Services\EmailEventProcessor;
use App\Services\Webhooks\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookEventsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 120;

    /** @param list<WebhookEvent> $events */
    public function __construct(public array $events)
    {
        $this->onQueue(config('mailing.queues.default'));
    }

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(EmailEventProcessor $processor): void
    {
        foreach ($this->events as $event) {
            // Safe to re-run after a failure: already-stored events are detected by their dedupe key.
            $processor->process($event);
        }
    }
}
