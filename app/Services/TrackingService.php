<?php

namespace App\Services;

use App\Jobs\RefreshCampaignStatsJob;
use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\DB;

/**
 * First-party open/click tracking. Open numbers are APPROXIMATE: image blocking hides real opens,
 * while Apple Mail Privacy Protection and security scanners fire "opens" nobody made.
 */
class TrackingService
{
    public function __construct(private EmailEventRecorder $recorder) {}

    public function findByToken(string $token): ?CampaignRecipient
    {
        return strlen($token) === 64 ? CampaignRecipient::where('tracking_token', $token)->first() : null;
    }

    public function recordOpen(CampaignRecipient $r, ?string $ip, ?string $userAgent): void
    {
        // One stored event per recipient per minute: image prefetching often fires several requests at once.
        $event = $this->recorder->record("tracking|open|{$r->id}|".intdiv(time(), 60), [
            'campaign_id' => $r->campaign_id,
            'campaign_recipient_id' => $r->id,
            'provider' => 'tracking',
            'event_type' => 'opened',
            'email' => $r->email,
            'ip' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            'occurred_at' => now(),
        ]);

        if (! $event) {
            return;
        }

        $isFirst = $r->first_opened_at === null;

        CampaignRecipient::whereKey($r->id)->update([
            'open_count' => DB::raw('open_count + 1'),
            'first_opened_at' => DB::raw('COALESCE(first_opened_at, CURRENT_TIMESTAMP)'),
        ]);

        if ($isFirst) {
            RefreshCampaignStatsJob::dispatch($r->campaign_id);
        }
    }

    public function recordClick(CampaignRecipient $r, string $url, ?string $ip, ?string $userAgent): void
    {
        $event = $this->recorder->record('tracking|click|'.$r->id.'|'.sha1($url).'|'.intdiv(time(), 10), [
            'campaign_id' => $r->campaign_id,
            'campaign_recipient_id' => $r->id,
            'provider' => 'tracking',
            'event_type' => 'clicked',
            'email' => $r->email,
            'url' => mb_substr($url, 0, 2000),
            'ip' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            'occurred_at' => now(),
        ]);

        if (! $event) {
            return;
        }

        $isFirst = $r->first_clicked_at === null;

        CampaignRecipient::whereKey($r->id)->update([
            'click_count' => DB::raw('click_count + 1'),
            'first_clicked_at' => DB::raw('COALESCE(first_clicked_at, CURRENT_TIMESTAMP)'),
        ]);

        if ($isFirst) {
            RefreshCampaignStatsJob::dispatch($r->campaign_id);
        }
    }
}
