@extends('layouts.app')
@section('title', 'SMTP Settings')
@section('content')
<div class="page-head">
    <div><h2>SMTP settings</h2><p>Connect your SMTP relay. Credentials are encrypted at rest and never shown again after saving. Rate limits protect your sender reputation.</p></div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#smtpModal" data-create-modal="#smtpModal" data-action="{{ route('smtp.store') }}" data-title="Add SMTP configuration"><i class="bi bi-plus-lg me-1"></i> Add configuration</button>
</div>

@forelse ($settings as $s)
<div class="cardx mb-3"><div class="cardx-body">
    <div class="d-flex flex-wrap justify-content-between gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h3 class="h6 fw-semibold mb-0">{{ $s->name }}</h3>
                @if ($s->is_default)<x-badge color="primary">Default</x-badge>@endif
                <x-badge :color="$s->is_active ? 'success' : 'secondary'">{{ $s->is_active ? 'Active' : 'Disabled' }}</x-badge>
                <x-badge color="secondary">{{ ucfirst($s->provider) }}</x-badge>
            </div>
            <div class="text-muted small mono">{{ $s->host }}:{{ $s->port }} · {{ strtoupper($s->encryption) }} · {{ $s->has_password ? 'password saved' : 'no password' }}</div>
            <div class="text-muted small">From {{ $s->from_name }} &lt;{{ $s->from_email }}&gt;@if ($s->reply_to_email) · Reply-to {{ $s->reply_to_email }}@endif</div>
            <div class="text-muted small mt-1">
                Limits: {{ $s->rate_per_second ?: $defaults['rate_per_second'] }}/s · {{ $s->rate_per_minute ?: $defaults['rate_per_minute'] }}/min · {{ number_format($s->daily_limit ?: $defaults['daily_limit']) }}/day
                @if ($s->last_tested_at) · Last test {{ $s->last_tested_at->diffForHumans() }}: <strong class="{{ $s->last_test_ok ? 'text-success' : 'text-danger' }}">{{ $s->last_test_ok ? 'OK' : 'failed' }}</strong>@endif
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <button class="btn btn-light" data-ajax-post="{{ route('smtp.test-connection', $s) }}"><i class="bi bi-plug me-1"></i>Test connection</button>
            <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#testMail{{ $s->id }}"><i class="bi bi-envelope-paper me-1"></i>Send test</button>
            <form method="POST" action="{{ route('smtp.toggle', $s) }}">@csrf<button class="btn btn-light">{{ $s->is_active ? 'Disable' : 'Enable' }}</button></form>
            <button class="btn btn-light" data-edit-modal="#smtpModal" data-title="Edit SMTP configuration" data-action="{{ route('smtp.update', $s) }}"
                data-values="{{ json_encode(['name' => $s->name, 'provider' => $s->provider, 'host' => $s->host, 'port' => $s->port, 'encryption' => $s->encryption, 'from_name' => $s->from_name, 'from_email' => $s->from_email, 'reply_to_email' => $s->reply_to_email, 'rate_per_second' => $s->rate_per_second, 'rate_per_minute' => $s->rate_per_minute, 'daily_limit' => $s->daily_limit, 'is_active' => $s->is_active, 'is_default' => $s->is_default]) }}"><i class="bi bi-pencil"></i></button>
            <form method="POST" action="{{ route('smtp.destroy', $s) }}" data-confirm="Delete “{{ $s->name }}”? Campaigns using it must be given another configuration." data-confirm-button="Delete">@csrf @method('DELETE')<button class="btn btn-light text-danger"><i class="bi bi-trash3"></i></button></form>
        </div>
    </div>
    @if ($s->provider !== 'generic' || $s->webhook_secret !== null)
        <hr>
    @endif
    <div class="small text-muted">Webhook URL for delivery events: <code class="user-select-all">{{ url('/webhooks/'.$s->provider) }}</code>
        @if ($s->provider === 'generic') · send <code>X-Signature: sha256=HMAC(body)</code> using the webhook secret.
        @elseif ($s->provider === 'sendgrid') · enable <em>Signed Event Webhook</em> and paste the public key as the secret.
        @elseif ($s->provider === 'mailgun') · paste the HTTP webhook signing key as the secret.
        @elseif ($s->provider === 'postmark') · set the secret as the <code>X-Postmark-Token</code> header value.
        @elseif ($s->provider === 'ses') · subscribe an HTTPS SNS topic to the URL and paste the TopicArn as the secret.
        @endif
    </div>
</div></div>

<div class="modal fade" id="testMail{{ $s->id }}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Send test email via {{ $s->name }}</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><label class="form-label">Send to</label><input id="testTo{{ $s->id }}" type="email" class="form-control" placeholder="you@example.com"></div>
    <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" data-ajax-post="{{ route('smtp.test-email', $s) }}" data-email-input="#testTo{{ $s->id }}"><i class="bi bi-send me-1"></i>Send</button></div>
</div></div></div>
@empty
    <div class="cardx"><x-empty-state icon="bi-hdd-network" title="No SMTP configuration yet">Add your relay to start sending campaigns.</x-empty-state></div>
@endforelse

<div class="modal fade" id="smtpModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <form method="POST" action="{{ old('_edit_action') ?: route('smtp.store') }}" data-no-loading autocomplete="off">
        @csrf
        @if (old('_edit_action')) @method('PUT') @endif
        <input type="hidden" name="_edit_action" value="{{ old('_edit_action') }}">
        <div class="modal-header"><h5 class="modal-title" data-modal-title>Add SMTP configuration</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            @if ($errors->any())<div class="alert alert-danger py-2">@foreach ($errors->all() as $er)<div>{{ $er }}</div>@endforeach</div>@endif
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Name *</label><input name="name" class="form-control" value="{{ old('name') }}" required></div>
                <div class="col-md-6"><label class="form-label">Provider *</label><select name="provider" class="form-select">@foreach ($providers as $p)<option value="{{ $p }}" @selected(old('provider', 'generic') === $p)>{{ ucfirst($p) }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label">Host *</label><input name="host" class="form-control" value="{{ old('host') }}" placeholder="smtp.example.com" required></div>
                <div class="col-md-3"><label class="form-label">Port *</label><input type="number" name="port" class="form-control" value="{{ old('port', 587) }}" required></div>
                <div class="col-md-3"><label class="form-label">Encryption *</label><select name="encryption" class="form-select">@foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'None'] as $v => $l)<option value="{{ $v }}" @selected(old('encryption', 'tls') === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label">Username</label><input name="username" class="form-control" value="" autocomplete="off" placeholder="Leave blank to keep the saved value"></div>
                <div class="col-md-6"><label class="form-label">Password</label><input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep the saved value"></div>
                <div class="col-md-6"><label class="form-label">From name *</label><input name="from_name" class="form-control" value="{{ old('from_name') }}" required></div>
                <div class="col-md-6"><label class="form-label">From email *</label><input type="email" name="from_email" class="form-control" value="{{ old('from_email') }}" required></div>
                <div class="col-md-6"><label class="form-label">Reply-to email</label><input type="email" name="reply_to_email" class="form-control" value="{{ old('reply_to_email') }}"></div>
                <div class="col-md-6"><label class="form-label">Webhook secret</label><input type="password" name="webhook_secret" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep the saved value"></div>
                <div class="col-md-4"><label class="form-label">Per second</label><input type="number" name="rate_per_second" class="form-control" value="{{ old('rate_per_second') }}" placeholder="{{ $defaults['rate_per_second'] }}"></div>
                <div class="col-md-4"><label class="form-label">Per minute</label><input type="number" name="rate_per_minute" class="form-control" value="{{ old('rate_per_minute') }}" placeholder="{{ $defaults['rate_per_minute'] }}"></div>
                <div class="col-md-4"><label class="form-label">Daily limit</label><input type="number" name="daily_limit" class="form-control" value="{{ old('daily_limit') }}" placeholder="{{ $defaults['daily_limit'] }}"></div>
                <div class="col-12 d-flex gap-4">
                    <input type="hidden" name="is_active" value="0"><label><input type="checkbox" class="form-check-input me-1" name="is_active" value="1" @checked(old('is_active', '1') == '1')> Active</label>
                    <input type="hidden" name="is_default" value="0"><label><input type="checkbox" class="form-check-input me-1" name="is_default" value="1" @checked(old('is_default'))> Default for new campaigns</label>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save configuration</button></div>
    </form>
</div></div></div>
@if ($errors->any())<span data-open-modal="#smtpModal" hidden></span>@endif
@endsection
