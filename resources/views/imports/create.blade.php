@extends('layouts.app')
@section('title', 'Import contacts')
@section('content')
@php $oldGroupIds = (array) old('group_ids', []); @endphp
<div class="page-head">
    <div>
        <h2>Import contacts from CSV</h2>
        <p>Large files are fine: rows are validated and imported in the background in chunks, so you can leave this page.</p>
    </div>
    <a href="{{ route('audiences.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i> Back to audiences</a>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="cardx">
            <div class="cardx-body">
                <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" data-no-loading id="importForm">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="file">CSV file</label>
                        <input id="file" type="file" name="file" accept=".csv,.txt,text/csv" class="form-control @error('file') is-invalid @enderror" required>
                        @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Maximum {{ $maxMb }} MB. The first row must be a header containing an <code>email</code> column. <a href="{{ route('imports.sample') }}">Download a sample</a>.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Add imported contacts to groups <span class="text-muted fw-normal">(optional)</span></label>
                        <div class="d-flex flex-wrap gap-2">
                            @forelse ($groups as $g)
                                <label class="border rounded-pill px-3 py-1 small"><input type="checkbox" class="form-check-input me-1" name="group_ids[]" value="{{ $g->id }}" @checked(in_array($g->id, $oldGroupIds))> {{ $g->name }}</label>
                            @empty <span class="text-muted small">No groups yet. <a href="{{ route('groups.index') }}">Create one</a>.</span> @endforelse
                        </div>
                    </div>
                    <button class="btn btn-primary" id="importBtn"><i class="bi bi-upload me-1"></i> Upload &amp; import</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="cardx h-100"><div class="cardx-body">
            <h3 class="h6 fw-semibold">What happens to each row</h3>
            <ul class="text-muted ps-3 mb-0 small">
                <li class="mb-2">Headers are matched case-insensitively: <code>email</code> (required), <code>name</code> (or <code>first_name</code> + <code>last_name</code>), <code>phone</code>, <code>company</code>. Other columns are ignored.</li>
                <li class="mb-2"><strong>Imported</strong>: valid, new addresses.</li>
                <li class="mb-2"><strong>Skipped</strong>: duplicates (in the file or already in your audience) and addresses on the suppression list.</li>
                <li class="mb-2"><strong>Failed</strong>: missing or badly formatted email. You can download the failed rows with the reason for each.</li>
                <li>Semicolon, tab and comma separated files are detected automatically.</li>
            </ul>
        </div></div>
    </div>
</div>

<h3 class="h6 fw-semibold mt-4 mb-3">Recent imports</h3>
<div class="table-wrap"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>File</th><th>Status</th><th>Imported</th><th>Skipped</th><th>Failed</th><th>When</th><th></th></tr></thead>
    <tbody>
    @forelse ($recent as $i)
        <tr>
            <td class="fw-semibold">{{ $i->original_filename }}</td>
            <td><x-badge :color="['completed' => 'success', 'failed' => 'danger', 'processing' => 'primary', 'pending' => 'secondary'][$i->status] ?? 'secondary'">{{ ucfirst($i->status) }}</x-badge></td>
            <td>{{ number_format($i->imported_count) }}</td><td>{{ number_format($i->skipped_count) }}</td><td>{{ number_format($i->failed_count) }}</td>
            <td class="small text-muted">{{ $i->created_at->diffForHumans() }}</td>
            <td class="text-end"><a class="btn btn-sm btn-light" href="{{ route('imports.show', $i) }}">Details</a></td>
        </tr>
    @empty
        <tr><td colspan="7"><x-empty-state icon="bi-filetype-csv" title="No imports yet">Your uploads will be listed here.</x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
@endsection

@push('scripts')
<script>
document.getElementById('importForm').addEventListener('submit', () => {
    const b = document.getElementById('importBtn'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
});
</script>
@endpush
