<?php

namespace App\Http\Controllers;

use App\Http\Requests\AudienceGroupRequest;
use App\Models\Audience;
use App\Models\AudienceGroup;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AudienceGroupController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): View
    {
        return view('groups.index', [
            'groups' => AudienceGroup::withCount('audiences')->orderBy('name')->get(),
            'totalContacts' => Audience::count(),
        ]);
    }

    public function store(AudienceGroupRequest $request): RedirectResponse
    {
        $group = AudienceGroup::create($request->validated() + ['color' => $request->input('color') ?: '#5b4cf5']);
        $this->activity->log('audience', 'group.created', "Group \"{$group->name}\" created");

        return back()->with('success', 'Group created.');
    }

    public function update(AudienceGroupRequest $request, AudienceGroup $group): RedirectResponse
    {
        $group->update($request->validated());
        $this->activity->log('audience', 'group.updated', "Group \"{$group->name}\" updated");

        return back()->with('success', 'Group updated.');
    }

    public function destroy(AudienceGroup $group): RedirectResponse
    {
        $name = $group->name;
        $group->delete(); // membership rows cascade; the contacts themselves are untouched
        $this->activity->log('audience', 'group.deleted', "Group \"{$name}\" deleted");

        return back()->with('success', 'Group deleted. Its contacts were kept.');
    }
}
