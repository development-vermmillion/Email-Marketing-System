<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $meta  Never put credentials or full contact payloads in here.
     */
    public function log(
        string $logName,
        string $action,
        ?string $description = null,
        Campaign|int|null $campaign = null,
        array $meta = [],
    ): CampaignActivity {
        return CampaignActivity::create([
            'campaign_id' => $campaign instanceof Campaign ? $campaign->getKey() : $campaign,
            'user_id' => Auth::id(),
            'log_name' => $logName,
            'action' => $action,
            'description' => $description,
            'meta' => $meta ?: null,
            'ip' => app()->runningInConsole() ? null : Request::ip(),
        ]);
    }
}
