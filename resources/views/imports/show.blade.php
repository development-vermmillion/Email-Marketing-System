@extends('layouts.app')
@section('title', 'Import details')
@section('content')
<div class="page-head">
    <div>
        <h2>{{ $import->original_filename }}</h2>
        <p>Started {{ $import->created_at->diffForHumans() }}. You can leave this page; the import continues in the background.</p>
    </div>
    <a href="{{ route('imports.create') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i> All imports</a>
</div>

<div @unless($import->isFinished()) data-progress-url="{{ route('imports.progress', $import) }}" data-reload-on-finish="1" @endunless>
    <div class="cardx mb-3"><div class="cardx-body">
        <div class="d-flex justify-content-between mb-2">
            <span class="fw-semibold">Progress <span class="text-muted fw-normal" data-field="progress_text">{{ $import->progress }}%</span></span>
            <span class="text-muted small"><span data-field="processed">{{ number_format($import->processed_rows) }}</span> / <span data-field="total">{{ number_format($import->total_rows) }}</span> rows</span>
        </div>
        <x-progress-bar :value="$import->progress" :large="true" field="progress" />
        @if ($import->status === 'failed')<div class="alert alert-danger mt-3 mb-0">Import failed: {{ $import->error }}</div>@endif
    </div></div>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="cardx stat"><div class="label"><span class="icon"><i class="bi bi-check2-circle"></i></span> Imported</div><div class="value-row"><span class="value" data-field="imported">{{ number_format($import->imported_count) }}</span></div></div></div>
        <div class="col-md-4"><div class="cardx stat"><div class="label"><span class="icon"><i class="bi bi-skip-forward"></i></span> Skipped</div><div class="value-row"><span class="value" data-field="skipped">{{ number_format($import->skipped_count) }}</span><span class="sub">duplicates / suppressed</span></div></div></div>
        <div class="col-md-4"><div class="cardx stat"><div class="label"><span class="icon"><i class="bi bi-x-circle"></i></span> Failed</div><div class="value-row"><span class="value" data-field="failed">{{ number_format($import->failed_count) }}</span><span class="sub">invalid rows</span></div></div></div>
    </div>
</div>

@if ($import->isFinished() && $import->results_path)
    <div class="cardx"><div class="cardx-body d-flex flex-wrap gap-2 align-items-center">
        <span class="me-auto text-muted">Download the rows that were not imported, with the reason for each.</span>
        <a class="btn btn-light" href="{{ route('imports.download', [$import, 'type' => 'all']) }}"><i class="bi bi-download me-1"></i> Import results (skipped + failed)</a>
        <a class="btn btn-outline-danger" href="{{ route('imports.download', [$import, 'type' => 'failed']) }}"><i class="bi bi-download me-1"></i> Failed records only</a>
        <a class="btn btn-primary" href="{{ route('audiences.index') }}">View audiences</a>
    </div></div>
@endif
@endsection
