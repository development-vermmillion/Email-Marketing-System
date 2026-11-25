@extends('layouts.app')
@section('title', 'Campaigns')
@section('content')
<div class="page-head">
    <div>
        <h2>Campaigns</h2>
        <p>Create, schedule and monitor email campaigns. Sending runs in the background, so you can close the browser.</p>
    </div>
    <a href="{{ route('campaigns.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New campaign</a>
</div>

<div class="tabs">
    <a href="{{ route('campaigns.index') }}" class="{{ ! $status ? 'active' : '' }}">All</a>
    @foreach (['draft', 'scheduled', 'queued', 'sending', 'paused', 'completed', 'failed', 'cancelled'] as $s)
        <a href="{{ route('campaigns.index', ['status' => $s]) }}" class="{{ $status === $s ? 'active' : '' }}">{{ ucfirst($s) }}<span class="count">{{ $counts[$s] ?? 0 }}</span></a>
    @endforeach
    <a href="{{ route('campaigns.index', ['status' => 'trashed']) }}" class="{{ $status === 'trashed' ? 'active' : '' }}"><i class="bi bi-trash3"></i> Trash<span class="count">{{ $trashedCount }}</span></a>
</div>

<form method="GET" class="filter-bar" data-no-loading>
    @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    <input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Search name or subject…">
    <button class="btn btn-light">Search</button>
</form>

@if ($status === 'trashed')
    <div class="alert alert-warning border-0"><i class="bi bi-info-circle me-1"></i> Campaigns stay in the trash for {{ config('mailing.trash_retention_days') }} days and are then deleted permanently, together with their recipient-level data. Suppression records are never removed.</div>
@endif

<div class="table-wrap">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Campaign</th><th>Status</th><th>Recipients</th><th style="min-width:160px">Progress</th><th>{{ $status === 'trashed' ? 'Deleted' : 'Schedule / sent' }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($campaigns as $c)
                <tr>
                    <td>
                        <a href="{{ route('campaigns.show', $c) }}" class="fw-semibold text-dark">{{ $c->name }}</a>
                        <div class="text-muted small">{{ \Illuminate\Support\Str::limit($c->subject, 60) }} · {{ $c->template?->name }}</div>
                    </td>
                    <td><x-badge :color="$c->status->badge()">{{ $c->status->label() }}</x-badge></td>
                    <td>{{ number_format($c->total_recipients) }}</td>
                    <td>
                        @if ($c->total_recipients)
                            <x-progress-bar :value="$c->progress" />
                            <div class="small text-muted mt-1">{{ $c->progress }}% · {{ number_format($c->sent_count) }} sent</div>
                        @else <span class="text-muted">—</span> @endif
                    </td>
                    <td class="small text-muted">
                        @if ($c->trashed()) {{ $c->deleted_at->diffForHumans() }}<br>purged {{ $c->deleted_at->addDays(config('mailing.trash_retention_days'))->diffForHumans() }}
                        @elseif ($c->scheduled_at && ! $c->completed_at) {{ $c->scheduled_at->format('M j, Y H:i') }}
                        @elseif ($c->started_at) {{ $c->started_at->format('M j, Y H:i') }}
                        @else — @endif
                    </td>
                    <td class="text-end row-actions">
                        @if ($c->trashed())
                            <form method="POST" action="{{ route('campaigns.restore', $c) }}" class="d-inline">@csrf<button class="btn btn-light"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
                        @else
                            <div class="dropdown d-inline">
                                <button class="btn btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="{{ route('campaigns.show', $c) }}"><i class="bi bi-eye"></i>View</a></li>
                                    @if ($c->canEdit())<li><a class="dropdown-item" href="{{ route('campaigns.edit', $c) }}"><i class="bi bi-pencil"></i>Edit</a></li>@endif
                                    <li><a class="dropdown-item" href="{{ route('reports.index', ['campaign_id' => $c->id]) }}"><i class="bi bi-bar-chart"></i>Report</a></li>
                                    <li><form method="POST" action="{{ route('campaigns.duplicate', $c) }}">@csrf<button class="dropdown-item"><i class="bi bi-copy"></i>Duplicate</button></form></li>
                                    @unless ($c->status->isActive())
                                    <li><hr class="dropdown-divider"></li>
                                    <li><form method="POST" action="{{ route('campaigns.destroy', $c) }}" data-confirm="Move “{{ $c->name }}” to the trash? You can restore it for {{ config('mailing.trash_retention_days') }} days." data-confirm-button="Move to trash">@csrf @method('DELETE')<button class="dropdown-item text-danger"><i class="bi bi-trash3"></i>Delete</button></form></li>
                                    @endunless
                                </ul>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty-state icon="bi-send" title="No campaigns found">Try a different filter or create a new campaign.</x-empty-state></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $campaigns->links() }}</div>
@endsection
