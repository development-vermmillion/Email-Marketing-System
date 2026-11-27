@extends('layouts.app')
@section('title', 'Validation run')
@section('content')
<div class="page-head">
    <div>
        <h2>Validation run #{{ $run->id }}</h2>
        <p>{{ $run->scopeLabel() }}@if ($run->smtp_probe) · with mailbox check@endif · started {{ $run->created_at->diffForHumans() }}@if ($run->user) by {{ $run->user->name }}@endif. It runs in the background, so you can leave this page.</p>
    </div>
    <div class="d-flex gap-2">
        @if ($run->isActive())
            <form method="POST" action="{{ route('validation.cancel', $run) }}" data-confirm="Cancel this run? Contacts already checked keep their result." data-confirm-button="Cancel run">@csrf<button class="btn btn-outline-danger">Cancel run</button></form>
        @endif
        <a href="{{ route('validation.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i> Email validation</a>
    </div>
</div>

<div @if ($run->isActive()) data-progress-url="{{ route('validation.progress', $run) }}" data-reload-on-finish="1" @endif>
    <div class="cardx mb-3"><div class="cardx-body">
        <div class="d-flex justify-content-between mb-2">
            <span class="fw-semibold">Progress <span class="text-muted fw-normal" data-field="progress_text">{{ $run->progress }}%</span> · <x-badge :color="['completed' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary', 'processing' => 'primary', 'pending' => 'secondary'][$run->status] ?? 'secondary'">{{ ucfirst($run->status) }}</x-badge></span>
            <span class="text-muted small"><span data-field="processed">{{ number_format($run->processed) }}</span> / <span data-field="total">{{ number_format($run->total) }}</span> contacts</span>
        </div>
        <x-progress-bar :value="$run->progress" :large="true" field="progress" />
        @if ($run->status === 'failed')<div class="alert alert-danger mt-3 mb-0">The run failed: {{ $run->error }}</div>@endif
    </div></div>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><x-stat-card label="Valid" :value="$run->valid_count" icon="bi-check-circle" field="valid" /></div>
        <div class="col-md-4"><x-stat-card label="Risky" :value="$run->risky_count" icon="bi-exclamation-triangle" field="risky" /></div>
        <div class="col-md-4"><x-stat-card label="Invalid" :value="$run->invalid_count" icon="bi-x-circle" field="invalid" /></div>
    </div>
</div>

@if ($run->isFinished())
    <div class="cardx"><div class="cardx-body d-flex flex-wrap gap-2 align-items-center">
        <span class="me-auto text-muted">@if ($run->deactivate_invalid) Contacts with invalid addresses were set to inactive. @else Invalid addresses are skipped by campaigns automatically. @endif</span>
        <a class="btn btn-light" href="{{ route('validation.export', ['status' => 'invalid']) }}"><i class="bi bi-download me-1"></i> Export invalid</a>
        <a class="btn btn-light" href="{{ route('validation.export', ['status' => 'risky']) }}"><i class="bi bi-download me-1"></i> Export risky</a>
        <a class="btn btn-primary" href="{{ route('audiences.index', ['validation' => 'invalid']) }}">Review invalid contacts</a>
    </div></div>
@endif
@endsection
