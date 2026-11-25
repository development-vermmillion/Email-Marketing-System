<?php

namespace App\Services;

use App\Models\Audience;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecipientPreparer
{
    /**
     * Contacts a campaign would reach right now. One query shape is shared by the preview count and
     * the real preparation so the two can never disagree:
     *   - active + subscribed + not soft-deleted
     *   - not on the suppression list
     *   - member of ANY selected group (whereHas = no duplicate rows when a contact is in 2+ groups)
     *
     * @param  array<int>|null  $groupIds
     */
    public function eligibleQuery(?array $groupIds): Builder
    {
        return Audience::query()
            ->sendable()
            ->whereNotIn('email', fn ($q) => $q->select('email')->from('suppression_list'))
            ->when(! empty($groupIds), fn (Builder $q) => $q->whereHas(
                'groups', fn (Builder $g) => $g->whereIn('audience_groups.id', $groupIds)
            ));
    }

    public function estimate(?array $groupIds): int
    {
        return $this->eligibleQuery($groupIds)->count();
    }

    /**
     * Creates the campaign_recipients rows (idempotent: UNIQUE(campaign_id, email) + INSERT IGNORE means
     * running it twice, e.g. after a worker restart, creates nothing new).
     */
    public function prepare(Campaign $campaign): int
    {
        $now = now();

        $this->eligibleQuery($campaign->group_ids)
            ->select(['id', 'email', 'name', 'company', 'phone'])
            ->chunkById(1000, function ($contacts) use ($campaign, $now) {
                $rows = $contacts->map(fn (Audience $a) => [
                    'campaign_id' => $campaign->id,
                    'audience_id' => $a->id,
                    'email' => $a->email,
                    'name' => $a->name,
                    'company' => $a->company,
                    'phone' => $a->phone,
                    'status' => 'pending',
                    'tracking_token' => Str::random(64),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('campaign_recipients')->insertOrIgnore($rows);
            });

        return (int) DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count();
    }
}
