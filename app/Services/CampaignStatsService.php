<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;

/**
 * Counters on `campaigns` are always recomputed from campaign_recipients (the source of truth),
 * never incremented in place. That makes them self-healing: replayed webhooks, retried jobs and
 * worker restarts cannot drift the numbers.
 */
class CampaignStatsService
{
    public function refresh(Campaign|int $campaign): void
    {
        $id = $campaign instanceof Campaign ? $campaign->getKey() : $campaign;

        $row = CampaignRecipient::query()
            ->where('campaign_id', $id)
            ->selectRaw("
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status IN ('pending','sending') THEN 1 ELSE 0 END), 0) AS queued,
                COALESCE(SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS sent,
                COALESCE(SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS delivered,
                COALESCE(SUM(CASE WHEN first_opened_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS opened,
                COALESCE(SUM(CASE WHEN first_clicked_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS clicked,
                COALESCE(SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS bounced,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                COALESCE(SUM(CASE WHEN complained_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS complained,
                COALESCE(SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS unsubscribed,
                COALESCE(SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END), 0) AS skipped
            ")
            ->first();

        Campaign::withTrashed()->whereKey($id)->update([
            'total_recipients' => (int) $row->total,
            'queued_count' => (int) $row->queued,
            'sent_count' => (int) $row->sent,
            'delivered_count' => (int) $row->delivered,
            'opened_count' => (int) $row->opened,
            'clicked_count' => (int) $row->clicked,
            'bounced_count' => (int) $row->bounced,
            'failed_count' => (int) $row->failed,
            'complained_count' => (int) $row->complained,
            'unsubscribed_count' => (int) $row->unsubscribed,
            'skipped_count' => (int) $row->skipped,
        ]);
    }
}
