@extends('layouts.app')
@section('title', 'Activity Log')
@section('content')
<div class="page-head"><div><h2>Activity log</h2><p>Who did what, and when: campaign actions, imports, SMTP changes and logins.</p></div></div>
<form method="GET" class="filter-bar" data-no-loading>
    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Search description…" style="min-width:240px">
    <select name="log" class="form-select"><option value="">All areas</option>@foreach ($logNames as $n)<option value="{{ $n }}" @selected(($filters['log'] ?? '') === $n)>{{ ucfirst($n) }}</option>@endforeach</select>
    <button class="btn btn-light"><i class="bi bi-funnel me-1"></i>Filter</button>
    @if (array_filter($filters))<a href="{{ route('activity.index') }}" class="btn btn-link">Reset</a>@endif
</form>
<div class="table-wrap"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>When</th><th>Area</th><th>Description</th><th>Campaign</th><th>User</th><th>IP</th></tr></thead>
    <tbody>
    @forelse ($logs as $l)
        <tr>
            <td class="small text-nowrap">{{ $l->created_at->format('M j, Y H:i:s') }}</td>
            <td><x-badge>{{ ucfirst($l->log_name) }}</x-badge></td>
            <td>{{ $l->description ?: $l->action }}</td>
            <td class="small">@if ($l->campaign)<a href="{{ route('campaigns.show', $l->campaign) }}">{{ \Illuminate\Support\Str::limit($l->campaign->name, 28) }}</a>@else — @endif</td>
            <td class="small">{{ $l->user?->name ?? 'System' }}</td>
            <td class="small mono text-muted">{{ $l->ip ?: '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="6"><x-empty-state icon="bi-clock-history" title="No activity yet">Actions will be recorded here.</x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $logs->links() }}</div>
@endsection
