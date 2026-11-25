@extends('layouts.app')
@section('title', 'Suppression List')
@section('content')
<div class="page-head">
    <div><h2>Suppression list</h2><p>Addresses that must never be emailed: unsubscribes, hard bounces, complaints and manual blocks. Entries survive contact deletion. Only manual entries can be removed.</p></div>
    <div class="d-flex gap-2">
        <a href="{{ route('suppression.export') }}" class="btn btn-light"><i class="bi bi-download me-1"></i> Export CSV</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#suppressModal"><i class="bi bi-plus-lg me-1"></i> Add addresses</button>
    </div>
</div>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-3">
    @foreach (['unsubscribed' => 'bi-person-dash', 'bounced' => 'bi-arrow-return-left', 'complained' => 'bi-flag', 'manual' => 'bi-slash-circle'] as $reason => $icon)
        <div class="col"><x-stat-card :label="ucfirst($reason)" :value="$counts[$reason] ?? 0" :icon="$icon" /></div>
    @endforeach
</div>

<form method="GET" class="filter-bar" data-no-loading>
    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Search email…" style="min-width:240px">
    <select name="reason" class="form-select"><option value="">Any reason</option>@foreach (\App\Enums\SuppressionReason::values() as $v)<option value="{{ $v }}" @selected(($filters['reason'] ?? '') === $v)>{{ ucfirst($v) }}</option>@endforeach</select>
    <button class="btn btn-light"><i class="bi bi-funnel me-1"></i>Filter</button>
    @if (array_filter($filters))<a href="{{ route('suppression.index') }}" class="btn btn-link">Reset</a>@endif
</form>

<div class="table-wrap"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Email</th><th>Reason</th><th>Source</th><th>Note</th><th>Added</th><th></th></tr></thead>
    <tbody>
    @forelse ($entries as $e)
        <tr>
            <td class="fw-semibold">{{ $e->email }}</td>
            <td><x-badge :color="['unsubscribed' => 'secondary', 'bounced' => 'danger', 'complained' => 'warning', 'manual' => 'primary'][$e->reason] ?? 'secondary'">{{ ucfirst($e->reason) }}</x-badge></td>
            <td class="small text-muted">{{ $e->source ?: '—' }}</td>
            <td class="small text-muted" style="max-width:280px">{{ \Illuminate\Support\Str::limit($e->note, 80) ?: '—' }}</td>
            <td class="small text-muted">{{ $e->created_at->format('M j, Y H:i') }}</td>
            <td class="text-end">
                @if ($e->reason === 'manual')
                    <form method="POST" action="{{ route('suppression.destroy', $e) }}" data-confirm="Remove {{ $e->email }} from the suppression list? They can be emailed again." data-confirm-button="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-light text-danger"><i class="bi bi-x-lg"></i></button></form>
                @else
                    <span class="text-muted small" title="Unsubscribes, bounces and complaints are permanent">locked</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6"><x-empty-state icon="bi-slash-circle" title="Nothing suppressed">Unsubscribes and bounces will appear here automatically.</x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $entries->links() }}</div>

<div class="modal fade" id="suppressModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST" action="{{ route('suppression.store') }}" data-no-loading>
        @csrf
        <div class="modal-header"><h5 class="modal-title">Block addresses</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            @if ($errors->any())<div class="alert alert-danger py-2">@foreach ($errors->all() as $er)<div>{{ $er }}</div>@endforeach</div>@endif
            <label class="form-label">Email addresses</label>
            <textarea name="emails" rows="5" class="form-control" placeholder="One per line, or separated by commas" required>{{ old('emails') }}</textarea>
            <label class="form-label mt-3">Note <span class="text-muted fw-normal">(optional)</span></label>
            <input name="note" class="form-control" maxlength="500" value="{{ old('note') }}">
            <div class="form-text">Blocked addresses are skipped by every future campaign and marked unsubscribed in your audience.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Block addresses</button></div>
    </form>
</div></div></div>
@if ($errors->any())<span data-open-modal="#suppressModal" hidden></span>@endif
@endsection
