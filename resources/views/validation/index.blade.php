@extends('layouts.app')
@section('title', 'Email Validation')
@section('content')
<div class="page-head">
    <div>
        <h2>Email validation</h2>
        <p>Find addresses that will bounce before you send. Checking is done on the address and its domain's mail records; recipients are never contacted.</p>
    </div>
    <a href="{{ route('audiences.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i> Back to audiences</a>
</div>

@if ($activeRun)
    <div class="alert alert-primary d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hourglass-split me-1"></i> A validation run is in progress ({{ $activeRun->progress }}%).</span>
        <a class="btn btn-sm btn-primary" href="{{ route('validation.show', $activeRun) }}">View progress</a>
    </div>
@endif

@unless ($dnsEnabled)
    <div class="alert alert-warning">Domain (DNS) checks are switched off (<code>MAILING_VALIDATION_DNS=false</code>), so only format, typo, disposable and shared-mailbox checks run.</div>
@endunless

<div class="row row-cols-2 row-cols-lg-4 g-3 mb-3">
    <div class="col"><a class="text-decoration-none" href="{{ route('audiences.index', ['validation' => 'valid']) }}"><x-stat-card label="Valid" :value="$counts['valid']" icon="bi-check-circle" /></a></div>
    <div class="col"><a class="text-decoration-none" href="{{ route('audiences.index', ['validation' => 'risky']) }}"><x-stat-card label="Risky" :value="$counts['risky']" icon="bi-exclamation-triangle" /></a></div>
    <div class="col"><a class="text-decoration-none" href="{{ route('audiences.index', ['validation' => 'invalid']) }}"><x-stat-card label="Invalid" :value="$counts['invalid']" icon="bi-x-circle" /></a></div>
    <div class="col"><a class="text-decoration-none" href="{{ route('audiences.index', ['validation' => 'unchecked']) }}"><x-stat-card label="Not checked" :value="$counts['unchecked']" icon="bi-question-circle" /></a></div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="cardx h-100"><div class="cardx-head"><h3>Check contacts</h3></div><div class="cardx-body">
            <form method="POST" action="{{ route('validation.store') }}" data-no-loading>
                @csrf
                @if ($errors->any())<div class="alert alert-danger py-2">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
                <div class="mb-3">
                    <label class="d-flex gap-2 py-1"><input type="radio" class="form-check-input mt-1" name="scope" value="unchecked" checked><span><strong>Contacts not checked yet</strong> <span class="text-muted">({{ number_format($counts['unchecked']) }})</span><br><span class="small text-muted">Recommended after an import.</span></span></label>
                    <label class="d-flex gap-2 py-1"><input type="radio" class="form-check-input mt-1" name="scope" value="all"><span><strong>Everyone</strong><br><span class="small text-muted">Re-check all contacts (addresses and domains change over time).</span></span></label>
                    <label class="d-flex gap-2 py-1"><input type="radio" class="form-check-input mt-1" name="scope" value="group"><span><strong>One group</strong></span></label>
                    <select name="group_id" class="form-select mt-1" style="max-width:320px"><option value="">Choose a group…</option>@foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select>
                </div>
                <label class="d-flex gap-2 mb-3"><input type="checkbox" class="form-check-input mt-1" name="deactivate_invalid" value="1"><span>Set contacts with invalid addresses to <strong>inactive</strong><br><span class="small text-muted">Reversible. Inactive contacts are left out of campaigns.</span></span></label>
                <label class="d-flex gap-2 mb-3"><input type="checkbox" class="form-check-input mt-1" name="smtp_probe" value="1" @disabled(! $probeEnabled) @checked($probeEnabled)><span>Also verify mailboxes with the recipient's mail server<br><span class="small text-muted">@if ($probeEnabled)Slower (about a second per address), and the most accurate way to find addresses that will bounce.@else Switched off. See "Mailbox check" below to enable it.@endif</span></span></label>
                <button class="btn btn-primary" @disabled($activeRun)><i class="bi bi-patch-check me-1"></i> Start validation</button>
            </form>
        </div></div>
    </div>
    <div class="col-xl-6">
        <div class="cardx mb-3"><div class="cardx-head"><h3>Check a single address</h3></div><div class="cardx-body">
            <div class="input-group">
                <input type="text" id="oneEmail" class="form-control" placeholder="name@company.com" autocomplete="off">
                <button class="btn btn-light" id="oneEmailBtn" type="button">Check</button>
            </div>
            @if ($probeEnabled)<label class="small mt-2"><input type="checkbox" class="form-check-input me-1" id="oneEmailDeep" checked> Also ask the recipient's mail server (takes a few seconds)</label>@endif
            <div id="oneEmailResult" class="mt-3 small" aria-live="polite"></div>
        </div></div>
        <div class="cardx"><div class="cardx-head"><h3>Clean-up</h3></div><div class="cardx-body d-flex flex-wrap gap-2">
            <a class="btn btn-light" href="{{ route('validation.export', ['status' => 'invalid']) }}"><i class="bi bi-download me-1"></i> Export invalid</a>
            <a class="btn btn-light" href="{{ route('validation.export', ['status' => 'risky']) }}"><i class="bi bi-download me-1"></i> Export risky</a>
            <form method="POST" action="{{ route('validation.deactivate') }}" data-confirm="Set all {{ number_format($counts['invalid']) }} contacts with invalid addresses to inactive?" data-confirm-button="Deactivate">
                @csrf <button class="btn btn-outline-danger" @disabled($counts['invalid'] === 0)><i class="bi bi-person-dash me-1"></i> Deactivate invalid contacts</button>
            </form>
            <p class="small text-muted w-100 mb-0 mt-1">
                @if ($skipInvalid) Contacts marked <strong>invalid</strong> are automatically left out of new campaigns. @else Invalid contacts are NOT skipped by campaigns (<code>MAILING_SKIP_INVALID_EMAILS=false</code>). @endif
            </p>
        </div></div>
    </div>
</div>

<div class="cardx mt-3"><div class="cardx-head"><h3>Mailbox check (SMTP)</h3>
    <span class="pill pill-{{ $probeAvailable ? 'success' : ($probeEnabled ? 'warning' : 'secondary') }}">{{ $probeAvailable ? 'On' : ($probeEnabled ? 'Paused: port 25 unreachable' : 'Off') }}</span></div>
<div class="cardx-body small">
    <p class="mb-2">This connects to each recipient's mail server and asks whether it would accept the address, then disconnects. <strong>No email is ever sent.</strong> It is how you find addresses that look fine but don't exist, which is where most bounces come from.</p>
    <ul class="text-muted ps-3 mb-3">
        <li>Needs <strong>outbound port 25</strong> open. Home and office connections, and many cloud hosts, block it. Use the test below.</li>
        <li>Large providers sometimes refuse to answer such questions, and some servers accept every address ("catch-all"). Those addresses are reported as <em>unconfirmed</em> or <em>catch-all</em>, never as invalid.</li>
        <li>Run it from your own server, not from a machine whose IP you rely on for sending: heavy probing can get an IP blocklisted.</li>
        <li>To enable: set <code>MAILING_VALIDATION_SMTP_PROBE=true</code> in <code>.env</code> (plus <code>MAILING_PROBE_HELO</code> to your server's public host name), then run <code>php artisan config:clear</code>.</li>
    </ul>
    <button class="btn btn-light btn-sm" type="button" id="probeTestBtn"><i class="bi bi-plug me-1"></i> Test port 25 from this machine</button>
    <span id="probeTestResult" class="ms-2" aria-live="polite"></span>
</div></div>

<div class="cardx mt-3"><div class="cardx-body">
    <h3 class="h6 fw-semibold">What the result means</h3>
    <div class="row small text-muted g-3">
        <div class="col-md-4"><x-badge color="success">Valid</x-badge> The format is correct and the domain can receive mail. With the mailbox check on, a green "Mailbox confirmed" means the server accepted it; otherwise it only means the domain can receive mail. Real bounces are still caught from webhooks and suppressed.</div>
        <div class="col-md-4"><x-badge color="warning">Risky</x-badge> Deliverable but a poor target: disposable inboxes, shared mailboxes such as info@ or support@, a likely typo (a suggestion is shown), or a server that accepts every address (catch-all). Still emailed; review them.</div>
        <div class="col-md-4"><x-badge color="danger">Invalid</x-badge> Bad format, no mail server, or (mailbox check) the server says the mailbox doesn't exist. These would bounce and hurt your sender reputation.</div>
    </div>
</div></div>

<h3 class="h6 fw-semibold mt-4 mb-3">Recent runs</h3>
<div class="table-wrap"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Scope</th><th>Status</th><th>Checked</th><th>Valid</th><th>Risky</th><th>Invalid</th><th>Started</th><th></th></tr></thead>
    <tbody>
    @forelse ($runs as $r)
        <tr>
            <td class="fw-semibold">{{ $r->scopeLabel() }}</td>
            <td><x-badge :color="['completed' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary', 'processing' => 'primary', 'pending' => 'secondary'][$r->status] ?? 'secondary'">{{ ucfirst($r->status) }}</x-badge></td>
            <td>{{ number_format($r->processed) }} / {{ number_format($r->total) }}</td>
            <td>{{ number_format($r->valid_count) }}</td><td>{{ number_format($r->risky_count) }}</td><td>{{ number_format($r->invalid_count) }}</td>
            <td class="small text-muted">{{ $r->created_at->diffForHumans() }}</td>
            <td class="text-end"><a class="btn btn-sm btn-light" href="{{ route('validation.show', $r) }}">Details</a></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state icon="bi-patch-check" title="No validation runs yet">Start one above.</x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
@endsection

@push('scripts')
<script>
(function () {
    const input = document.getElementById('oneEmail'), btn = document.getElementById('oneEmailBtn'), out = document.getElementById('oneEmailResult');
    const colors = { valid: 'success', risky: 'warning', invalid: 'danger' };
    const run = async () => {
        const email = input.value.trim();
        if (!email) return;
        btn.disabled = true; out.textContent = 'Checking…';
        try {
            const res = await fetch('{{ route('validation.check') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': EMS.csrf },
                body: JSON.stringify({ email, deep: !!document.getElementById('oneEmailDeep')?.checked }),
            });
            const d = await res.json();
            if (!res.ok) throw new Error(d.message || 'Could not check this address');
            out.innerHTML = '';
            const pill = document.createElement('span'); pill.className = 'pill pill-' + (colors[d.status] || 'secondary'); pill.textContent = d.status;
            const msg = document.createElement('span'); msg.className = 'ms-2'; msg.textContent = d.message;
            out.append(pill, msg);
            if (document.getElementById('oneEmailDeep')?.checked && !d.mailbox_checked) { const n = document.createElement('div'); n.className = 'text-muted mt-1'; n.textContent = 'Mailbox check did not run (switched off or port 25 unreachable).'; out.append(n); }
        } catch (e) { out.textContent = e.message; }
        btn.disabled = false;
    };
    btn.addEventListener('click', run);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
    const tb = document.getElementById('probeTestBtn'), tr = document.getElementById('probeTestResult');
    tb.addEventListener('click', async () => {
        tb.disabled = true; tr.className = 'ms-2 text-muted'; tr.textContent = 'Testing (up to 20 seconds)…';
        try {
            const res = await fetch('{{ route('validation.probe-test') }}', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': EMS.csrf } });
            const d = await res.json();
            tr.className = 'ms-2 ' + (d.ok ? 'text-success' : 'text-danger'); tr.textContent = d.message || 'No answer';
        } catch (e) { tr.className = 'ms-2 text-danger'; tr.textContent = 'The test could not be run.'; }
        tb.disabled = false;
    });
})();
</script>
@endpush
