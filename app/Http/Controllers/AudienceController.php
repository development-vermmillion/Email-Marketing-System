<?php

namespace App\Http\Controllers;

use App\Http\Requests\AudienceRequest;
use App\Http\Requests\BulkIdsRequest;
use App\Models\Audience;
use App\Models\AudienceGroup;
use App\Services\AudienceService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AudienceController extends Controller
{
    public function __construct(private AudienceService $audiences) {}

    public function index(Request $request): View
    {
        $query = Audience::query()
            ->with('groups')
            ->search($request->query('q'))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('subscription'), fn ($q, $v) => $q->where('subscription_status', $v))
            ->when($request->query('validation'), fn ($q, $v) => $v === 'unchecked'
                ? $q->whereNull('email_validation_status')
                : $q->where('email_validation_status', $v))
            ->when($request->query('group'), fn ($q, $id) => $q->whereHas('groups', fn ($g) => $g->where('audience_groups.id', $id)));

        return view('audiences.index', [
            'audiences' => $query->latest('id')->paginate(25)->withQueryString(),
            'groups' => AudienceGroup::orderBy('name')->get(),
            'totals' => [
                'all' => Audience::count(),
                'subscribed' => Audience::sendable()->count(),
                'unsubscribed' => Audience::where('subscription_status', 'unsubscribed')->count(),
            ],
            'filters' => $request->only(['q', 'status', 'subscription', 'group', 'validation']),
        ]);
    }

    public function store(AudienceRequest $request): RedirectResponse
    {
        try {
            $this->audiences->create($request->validated(), $request->input('group_ids', []), $request->ip());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Contact added.');
    }

    public function update(AudienceRequest $request, Audience $audience): RedirectResponse
    {
        try {
            $this->audiences->update($audience, $request->validated(), $request->input('group_ids', []));
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Audience $audience): RedirectResponse
    {
        $this->audiences->delete([$audience->id]);

        return back()->with('success', 'Contact deleted. It will not receive any pending emails.');
    }

    public function bulkDestroy(BulkIdsRequest $request): RedirectResponse
    {
        $count = $this->audiences->delete($request->validated('ids'));

        return back()->with('success', "{$count} contact(s) deleted. They were removed from every pending send.");
    }

    public function bulkGroups(BulkIdsRequest $request): RedirectResponse
    {
        $groupIds = $request->validated('group_ids') ?? [];

        if ($groupIds === []) {
            return back()->with('error', 'Choose at least one group.');
        }

        $ids = Audience::query()->whereIn('id', $request->validated('ids'))->pluck('id');

        if ($request->input('mode') === 'remove') {
            DB::table('audience_group_contacts')->whereIn('audience_id', $ids)->whereIn('audience_group_id', $groupIds)->delete();
        } else {
            $now = now();
            foreach ($ids->chunk(500) as $chunk) {
                $rows = [];
                foreach ($chunk as $id) {
                    foreach ($groupIds as $gid) {
                        $rows[] = ['audience_id' => $id, 'audience_group_id' => $gid, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                DB::table('audience_group_contacts')->insertOrIgnore($rows);
            }
        }

        return back()->with('success', 'Group membership updated for '.$ids->count().' contact(s).');
    }
}
