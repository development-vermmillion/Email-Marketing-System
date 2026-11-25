@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<div class="page-head"><div><h2>Settings</h2><p>Organisation details shown in emails and on the unsubscribe page, tracking options and system status.</p></div></div>
<div class="row g-3">
    <div class="col-xl-7">
        <form method="POST" action="{{ route('settings.update') }}" class="cardx"><div class="cardx-body">
            @csrf @method('PUT')
            <div class="mb-3"><label class="form-label">Company name *</label><input name="company_name" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name', $settings['company_name']) }}" required>@error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="mb-3"><label class="form-label">Postal address (email footer)</label><textarea name="sender_address" rows="2" class="form-control" maxlength="500">{{ old('sender_address', $settings['sender_address']) }}</textarea><div class="form-text">Many jurisdictions require a physical address in marketing email.</div></div>
            <div class="mb-3"><label class="form-label">Message on the unsubscribe page</label><input name="public_unsubscribe_notice" class="form-control" maxlength="500" value="{{ old('public_unsubscribe_notice', $settings['public_unsubscribe_notice']) }}"></div>
            <div class="mb-3">
                <div class="form-check form-switch"><input type="hidden" name="track_opens" value="0"><input class="form-check-input" type="checkbox" name="track_opens" value="1" id="to" @checked(old('track_opens', $settings['track_opens']))><label class="form-check-label" for="to">Track opens (tiny pixel; approximate)</label></div>
                <div class="form-check form-switch mt-2"><input type="hidden" name="track_clicks" value="0"><input class="form-check-input" type="checkbox" name="track_clicks" value="1" id="tc" @checked(old('track_clicks', $settings['track_clicks']))><label class="form-check-label" for="tc">Track clicks (links are rewritten through a signed redirect)</label></div>
            </div>
            <button class="btn btn-primary">Save settings</button>
        </div></form>
    </div>
    <div class="col-xl-5">
        <div class="cardx"><div class="cardx-head"><h3>System status</h3></div><div class="cardx-body">
            <dl class="row mb-0 small">
                <dt class="col-7">Queue driver</dt><dd class="col-5 mono">{{ $system['queue_connection'] }}</dd>
                <dt class="col-7">Emails waiting</dt><dd class="col-5">{{ $system['emails_waiting'] ?? 'unavailable' }}</dd>
                <dt class="col-7">Imports waiting</dt><dd class="col-5">{{ $system['imports_waiting'] ?? 'unavailable' }}</dd>
                <dt class="col-7">Failed jobs</dt><dd class="col-5">{{ $system['failed_jobs'] ?? 'unavailable' }}</dd>
                <dt class="col-7">Default rate</dt><dd class="col-5">{{ $defaults['rate_per_second'] }}/s · {{ $defaults['rate_per_minute'] }}/min</dd>
                <dt class="col-7">Daily limit</dt><dd class="col-5">{{ number_format($defaults['daily_limit']) }}</dd>
                <dt class="col-7">Trash retention</dt><dd class="col-5">{{ $defaults['trash_retention_days'] }} days</dd>
            </dl>
            @if (($system['emails_waiting'] ?? 0) > 0)<p class="text-muted small mt-3 mb-0">If this number does not fall, check that the queue worker is running (see docs/DEPLOYMENT.md).</p>@endif
        </div></div>
    </div>
</div>
@endsection
