@extends('layouts.app')
@section('title', 'Audiences')
@section('content')
@php $oldGroupIds = (array) old('group_ids', []); @endphp
<div class="page-head">
    <div>
        <h2>Audiences</h2>
        <p>Everyone you can email. Deleting a contact removes them from all pending sends immediately; unsubscribes and bounces are kept on the suppression list.</p>
    </div>
    <div class="d-flex gap-2">
    <a href="{{ route('validation.index') }}" class="btn btn-light"><i class="bi bi-patch-check me-1"></i> Validate emails</a>
    <div class="dropdown">
        <button class="btn btn-dark dropdown-toggle text-uppercase" style="font-family:var(--mono);letter-spacing:.04em;font-size:.8rem" data-bs-toggle="dropdown">Add contacts</button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="{{ route('imports.create') }}"><i class="bi bi-filetype-csv"></i>Import via CSV</a></li>
            <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#audienceModal" data-create-modal="#audienceModal" data-action="{{ route('audiences.store') }}" data-title="Add contact"><i class="bi bi-person-plus"></i>Add Manually</button></li>
        </ul>
    </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-4"><x-stat-card label="All contacts" :value="$totals['all']" icon="bi-people" /></div>
    <div class="col-sm-4"><x-stat-card label="Active subscribers" :value="$totals['subscribed']" icon="bi-person-check" /></div>
    <div class="col-sm-4"><x-stat-card label="Unsubscribed" :value="$totals['unsubscribed']" icon="bi-person-dash" /></div>
</div>

<form method="GET" class="filter-bar" data-no-loading>
    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Search name or email…" style="min-width:240px">
    <select name="status" class="form-select"><option value="">Any status</option>@foreach (['active', 'inactive'] as $v)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ ucfirst($v) }}</option>@endforeach</select>
    <select name="subscription" class="form-select"><option value="">Any subscription</option>@foreach (\App\Enums\SubscriptionStatus::values() as $v)<option value="{{ $v }}" @selected(($filters['subscription'] ?? '') === $v)>{{ ucfirst($v) }}</option>@endforeach</select>
    <select name="validation" class="form-select"><option value="">Any email check</option>@foreach (['valid' => 'Valid', 'risky' => 'Risky', 'invalid' => 'Invalid', 'unchecked' => 'Not checked'] as $v => $l)<option value="{{ $v }}" @selected(($filters['validation'] ?? '') === $v)>{{ $l }}</option>@endforeach</select>
    <select name="group" class="form-select"><option value="">Any group</option>@foreach ($groups as $g)<option value="{{ $g->id }}" @selected((int) ($filters['group'] ?? 0) === $g->id)>{{ $g->name }}</option>@endforeach</select>
    <button class="btn btn-light"><i class="bi bi-funnel me-1"></i>Filter</button>
    @if (array_filter($filters))<a href="{{ route('audiences.index') }}" class="btn btn-link">Reset</a>@endif
</form>

<div class="bulk-bar" id="bulkBar">
    <strong><span data-bulk-count>0</span> selected</strong>
    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#groupsModal"><i class="bi bi-collection me-1"></i>Groups…</button>
    <form method="POST" action="{{ route('audiences.bulk-delete') }}" data-bulk-form data-confirm="Delete the selected contacts? They are removed from all pending sends immediately." data-confirm-button="Delete contacts">
        @csrf <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1"></i>Delete selected</button>
    </form>
</div>

<div class="table-wrap">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr>
                <th style="width:40px"><input type="checkbox" class="form-check-input" data-select-all aria-label="Select all"></th>
                <th>Contact</th><th>Company</th><th>Groups</th><th>Status</th><th>Subscription</th><th>Email check</th><th>Added</th><th></th>
            </tr></thead>
            <tbody>
            @forelse ($audiences as $a)
                <tr>
                    <td><input type="checkbox" class="form-check-input row-check" value="{{ $a->id }}" aria-label="Select {{ $a->email }}"></td>
                    <td>
                        <div class="fw-semibold">{{ $a->name ?: '—' }}</div>
                        <div class="text-muted small">{{ $a->email }}@if ($a->phone) · {{ $a->phone }}@endif</div>
                    </td>
                    <td>{{ $a->company ?: '—' }}</td>
                    <td>@foreach ($a->groups as $g)<span class="pill pill-secondary me-1" style="margin-bottom:2px">{{ $g->name }}</span>@endforeach</td>
                    <td><x-badge :color="$a->status === 'active' ? 'success' : 'secondary'">{{ ucfirst($a->status) }}</x-badge></td>
                    <td><x-badge :color="\App\Enums\SubscriptionStatus::from($a->subscription_status)->badge()">{{ ucfirst($a->subscription_status) }}</x-badge></td>
                    <td>@if ($a->email_validation_status)<x-badge :color="['valid' => 'success', 'risky' => 'warning', 'invalid' => 'danger'][$a->email_validation_status] ?? 'secondary'"><span title="{{ \App\Support\ValidationResult::label($a->email_validation_reason) }}">{{ ucfirst($a->email_validation_status) }}</span></x-badge>@else<span class="text-muted small">Not checked</span>@endif</td>
                    <td class="small text-muted">{{ $a->created_at->format('M j, Y') }}<br>{{ $a->source === 'csv_import' ? 'CSV import' : ucfirst($a->source) }}</td>
                    <td class="text-end row-actions">
                        <button class="btn btn-light" title="Edit" data-edit-modal="#audienceModal" data-title="Edit contact" data-action="{{ route('audiences.update', $a) }}"
                            data-values="{{ json_encode(['name' => $a->name, 'email' => $a->email, 'phone' => $a->phone, 'company' => $a->company, 'status' => $a->status, 'subscription_status' => $a->subscription_status === 'subscribed' ? 'subscribed' : 'unsubscribed', 'group_ids' => $a->groups->pluck('id')]) }}"><i class="bi bi-pencil"></i></button>
                        <form method="POST" action="{{ route('audiences.destroy', $a) }}" class="d-inline" data-confirm="Delete {{ $a->email }}? They will be removed from all pending sends." data-confirm-button="Delete">@csrf @method('DELETE')<button class="btn btn-light text-danger" title="Delete"><i class="bi bi-trash3"></i></button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><x-empty-state icon="bi-people" title="No contacts found">Add a contact manually or import a CSV file.</x-empty-state></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $audiences->links() }}</div>

{{-- Add / edit modal --}}
<div class="modal fade" id="audienceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST" action="{{ old('_edit_action') ?: route('audiences.store') }}" data-no-loading>
        @csrf
        @if (old('_edit_action')) @method('PUT') @endif
        <input type="hidden" name="_form" value="audience">
        <input type="hidden" name="_edit_action" value="{{ old('_edit_action') }}">
        <div class="modal-header"><h5 class="modal-title" data-modal-title>Add contact</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            @if ($errors->any() && old('_form') === 'audience')
                <div class="alert alert-danger py-2">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
            @endif
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Name</label><input name="name" class="form-control" value="{{ old('name') }}" maxlength="255"></div>
                <div class="col-12"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" value="{{ old('email') }}" required></div>
                <div class="col-md-6"><label class="form-label">Phone</label><input name="phone" class="form-control" value="{{ old('phone') }}" maxlength="50"></div>
                <div class="col-md-6"><label class="form-label">Company</label><input name="company" class="form-control" value="{{ old('company') }}" maxlength="255"></div>
                <div class="col-md-6"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                <div class="col-md-6"><label class="form-label">Subscription</label><select name="subscription_status" class="form-select"><option value="subscribed">Subscribed</option><option value="unsubscribed">Unsubscribed</option></select></div>
                <div class="col-12">
                    <label class="form-label">Groups</label>
                    <div class="d-flex flex-wrap gap-2">
                        @forelse ($groups as $g)
                            <label class="border rounded-pill px-3 py-1 small"><input type="checkbox" class="form-check-input me-1" name="group_ids[]" value="{{ $g->id }}" @checked(in_array($g->id, $oldGroupIds))> {{ $g->name }}</label>
                        @empty <span class="text-muted small">No groups yet.</span> @endforelse
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save contact</button></div>
    </form>
</div></div></div>

{{-- Bulk group modal --}}
<div class="modal fade" id="groupsModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST" action="{{ route('audiences.bulk-groups') }}" data-bulk-form>
        @csrf
        <div class="modal-header"><h5 class="modal-title">Update groups for selected contacts</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="btn-group w-100 mb-3" role="group">
                <input type="radio" class="btn-check" name="mode" id="modeAdd" value="add" checked><label class="btn btn-outline-primary" for="modeAdd">Add to groups</label>
                <input type="radio" class="btn-check" name="mode" id="modeRemove" value="remove"><label class="btn btn-outline-primary" for="modeRemove">Remove from groups</label>
            </div>
            @foreach ($groups as $g)
                <label class="d-flex align-items-center gap-2 py-1"><input type="checkbox" class="form-check-input mt-0" name="group_ids[]" value="{{ $g->id }}"><span class="dot" style="background:{{ $g->color }}"></span>{{ $g->name }}</label>
            @endforeach
            @if ($groups->isEmpty())<span class="text-muted">Create a group first.</span>@endif
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Apply</button></div>
    </form>
</div></div></div>

@if ($errors->any() && old('_form') === 'audience')<span data-open-modal="#audienceModal" hidden></span>@endif
@endsection
