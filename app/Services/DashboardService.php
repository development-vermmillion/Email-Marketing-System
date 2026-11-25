<?php

namespace App\Services;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Carbon\CarbonPeriod;

class DashboardService
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $totals = Campaign::query()->selectRaw('
            COALESCE(SUM(sent_count),0) AS sent, COALESCE(SUM(delivered_count),0) AS delivered,
            COALESCE(SUM(opened_count),0) AS opened, COALESCE(SUM(clicked_count),0) AS clicked,
            COALESCE(SUM(bounced_count),0) AS bounced, COALESCE(SUM(failed_count),0) AS failed
        ')->first();

        return [
            'audiences' => Audience::count(),
            'active_subscribers' => Audience::sendable()->count(),
            'campaigns' => Campaign::count(),
            'sent' => (int) $totals->sent,
            'delivered' => (int) $totals->delivered,
            'opened' => (int) $totals->opened,
            'clicked' => (int) $totals->clicked,
            'bounced' => (int) $totals->bounced,
            'failed' => (int) $totals->failed,
            'unsubscribed' => Audience::where('subscription_status', 'unsubscribed')->count(),
            'recent_campaigns' => Campaign::with('template:id,name')->latest('id')->limit(6)->get(),
            'charts' => [
                'daily' => $this->dailyActivity(30),
                'campaigns' => $this->campaignPerformance(8),
                'outcomes' => [
                    'labels' => ['Delivered', 'Bounced', 'Failed', 'Unsubscribed'],
                    'data' => [(int) $totals->delivered, (int) $totals->bounced, (int) $totals->failed, Audience::where('subscription_status', 'unsubscribed')->count()],
                ],
            ],
        ];
    }

    /** @return array{labels: list<string>, sent: list<int>, opened: list<int>, clicked: list<int>} */
    public function dailyActivity(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $series = fn (string $column) => CampaignRecipient::query()
            ->where($column, '>=', $from)
            ->selectRaw("DATE({$column}) AS d, COUNT(*) AS c")
            ->groupBy('d')
            ->pluck('c', 'd');

        $sent = $series('sent_at');
        $opened = $series('first_opened_at');
        $clicked = $series('first_clicked_at');

        $out = ['labels' => [], 'sent' => [], 'opened' => [], 'clicked' => []];

        foreach (CarbonPeriod::create($from, now()->startOfDay()) as $day) {
            $key = $day->toDateString();
            $out['labels'][] = $day->format('M j');
            $out['sent'][] = (int) ($sent[$key] ?? 0);
            $out['opened'][] = (int) ($opened[$key] ?? 0);
            $out['clicked'][] = (int) ($clicked[$key] ?? 0);
        }

        return $out;
    }

    /** @return array{labels: list<string>, sent: list<int>, delivered: list<int>, opened: list<int>, clicked: list<int>} */
    public function campaignPerformance(int $limit): array
    {
        $campaigns = Campaign::query()->where('sent_count', '>', 0)->latest('id')->limit($limit)->get()->reverse()->values();

        return [
            'labels' => $campaigns->map(fn ($c) => mb_strimwidth($c->name, 0, 18, '…'))->all(),
            'sent' => $campaigns->pluck('sent_count')->map(fn ($v) => (int) $v)->all(),
            'delivered' => $campaigns->pluck('delivered_count')->map(fn ($v) => (int) $v)->all(),
            'opened' => $campaigns->pluck('opened_count')->map(fn ($v) => (int) $v)->all(),
            'clicked' => $campaigns->pluck('clicked_count')->map(fn ($v) => (int) $v)->all(),
        ];
    }
}
