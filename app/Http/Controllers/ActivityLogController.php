<?php

namespace App\Http\Controllers;

use App\Models\CampaignActivity;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = CampaignActivity::query()
            ->with(['user:id,name', 'campaign:id,name'])
            ->when($request->query('log'), fn ($q, $v) => $q->where('log_name', $v))
            ->when($request->query('q'), fn ($q, $term) => $q->where('description', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%'))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('activity.index', [
            'logs' => $logs,
            'logNames' => CampaignActivity::query()->distinct()->orderBy('log_name')->pluck('log_name'),
            'filters' => $request->only(['q', 'log']),
        ]);
    }
}
