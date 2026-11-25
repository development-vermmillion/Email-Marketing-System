<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    /** @param array<string, mixed> $filters */
    public function campaignsQuery(array $filters): Builder
    {
        return Campaign::query()
            ->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id');
    }

    /**
     * Recipient-level report. Filters: campaign, date range (on send time, falling back to creation time),
     * sending status, and email address.
     *
     * @param  array<string, mixed>  $filters
     */
    public function recipientsQuery(array $filters): Builder
    {
        return CampaignRecipient::query()
            ->with('campaign:id,name')
            ->when($filters['campaign_id'] ?? null, fn ($q, $id) => $q->where('campaign_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['email'] ?? null, function ($q, $email) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($email)).'%';

                return $q->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like));
            })
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereRaw('DATE(COALESCE(sent_at, created_at)) >= ?', [$d]))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereRaw('DATE(COALESCE(sent_at, created_at)) <= ?', [$d]))
            ->orderBy('id');
    }
}
