@extends('layouts.app')
@section('title', 'Email Reports')
@section('content')
<div class="page-head">
    <div><h2>Email reports</h2><p>Campaign-level and recipient-level results. Delivery, bounce and complaint figures depend on your provider's webhooks.</p></div>
    <a class="btn btn-primary" href="{{ route('reports.export', $filters) }}"><i class="bi bi-download me-1"></i> Export recipients (CSV)</a>
</div>

<form method="GET" class="cardx mb-3" data-no-loading><div class="cardx-body"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Campaign</label>
        <select name="campaign_id" class="form-select"><option value="">All campaigns</option>@foreach ($campaignOptions as $o)<option value="{{ $o->id }}" @selected((int) ($filters['campaign_id'] ?? 0) === $o->id)>{{ \Illuminate\Support\Str::limit($o->name, 40) }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label">Sending status</label>
        <select name="status" class="form-select"><option value="">Any</option>@foreach ($statuses as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}"></div>
    <div class="col-md-2"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}"></div>
    <div class="col-md-3"><label class="form-label">Email / name</label><input type="search" name="email" class="form-control" value="{{ $filters['email'] ?? '' }}" placeholder="jane@…"></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply filters</button>@if (array_filter($filters))<a href="{{ route('reports.index') }}" class="btn btn-light">Reset</a>@endif</div>
</div></div></form>

<div class="row row-cols-2 row-cols-md-5 g-3 mb-3">
    <div class="col"><x-stat-card label="Recipients" :value="$totals->total" icon="bi-people" /></div>
    <div class="col"><x-stat-card label="Queued" :value="$totals->queued" icon="bi-hourglass-split" /></div>
    <div class="col"><x-stat-card label="Sent" :value="$totals->sent" icon="bi-envelope-arrow-up" /></div>
    <div class="col"><x-stat-card label="Delivered" :value="$totals->delivered" icon="bi-envelope-check" :sub="$rates['delivery'].'%'" /></div>
    <div class="col"><x-stat-card label="Opened" :value="$totals->opened" icon="bi-envelope-open" :sub="$rates['open'].'% · approx.'" /></div>
    <div class="col"><x-stat-card label="Clicked" :value="$totals->clicked" icon="bi-cursor" :sub="$rates['click'].'%'" /></div>
    <div class="col"><x-stat-card label="Bounced" :value="$totals->bounced" icon="bi-arrow-return-left" :sub="$rates['bounce'].'%'" /></div>
    <div class="col"><x-stat-card label="Failed" :value="$totals->failed" icon="bi-x-octagon" /></div>
    <div class="col"><x-stat-card label="Complaints" :value="$totals->complained" icon="bi-flag" /></div>
    <div class="col"><x-stat-card label="Unsubscribed" :value="$totals->unsubscribed" icon="bi-person-dash" :sub="$rates['unsubscribe'].'%'" /></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="cardx h-100"><div class="cardx-head"><h3>Engagement funnel</h3></div><div class="cardx-body"><div class="chart-box sm"><canvas id="funnel"></canvas></div></div></div></div>
    <div class="col-lg-5"><div class="cardx h-100"><div class="cardx-head"><h3>Rates</h3></div><div class="cardx-body"><div class="chart-box sm"><canvas id="rates"></canvas></div>
        <p class="text-muted small mb-0 mt-2">Open rate is approximate: privacy features and automated image loading distort it. "Sent" = accepted by the SMTP relay, not confirmed delivery.</p></div></div></div>
</div>

<h3 class="h6 fw-semibold mb-3">Campaigns</h3>
<div class="table-wrap mb-4"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Opened</th><th>Clicked</th><th>Bounced</th><th>Unsub.</th></tr></thead>
    <tbody>
    @forelse ($campaigns as $c)
        <tr>
            <td><a href="{{ route('reports.index', array_merge($filters, ['campaign_id' => $c->id])) }}" class="fw-semibold text-dark">{{ $c->name }}</a><div class="small text-muted">{{ $c->created_at->format('M j, Y') }}</div></td>
            <td><x-badge :color="$c->status->badge()">{{ $c->status->label() }}</x-badge></td>
            <td>{{ number_format($c->sent_count) }}</td>
            <td>{{ number_format($c->delivered_count) }} <span class="text-muted small">{{ $c->delivery_rate }}%</span></td>
            <td>{{ number_format($c->opened_count) }} <span class="text-muted small">{{ $c->open_rate }}%</span></td>
            <td>{{ number_format($c->clicked_count) }} <span class="text-muted small">{{ $c->click_rate }}%</span></td>
            <td>{{ number_format($c->bounced_count) }} <span class="text-muted small">{{ $c->bounce_rate }}%</span></td>
            <td>{{ number_format($c->unsubscribed_count) }} <span class="text-muted small">{{ $c->unsubscribe_rate }}%</span></td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state icon="bi-bar-chart" title="No campaigns match">Adjust the filters.</x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mb-4">{{ $campaigns->links() }}</div>

<h3 class="h6 fw-semibold mb-3">Recipients</h3>
<div class="table-wrap"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Recipient</th><th>Campaign</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Opened</th><th>Clicked</th><th>Reason</th></tr></thead>
    <tbody>
    @forelse ($recipients as $r)
        <tr>
            <td><div class="fw-semibold">{{ $r->name ?: '—' }}</div><div class="small text-muted">{{ $r->email }}</div></td>
            <td class="small">{{ \Illuminate\Support\Str::limit($r->campaign?->name, 28) }}</td>
            <td><x-badge :color="$r->status->badge()">{{ $r->status->label() }}</x-badge></td>
            <td class="small">{{ $r->sent_at?->format('M j, H:i') ?? '—' }}</td>
            <td class="small">{{ $r->delivered_at?->format('M j, H:i') ?? '—' }}</td>
            <td class="small">{{ $r->first_opened_at?->format('M j, H:i') ?? '—' }}@if ($r->open_count > 1) <span class="text-muted">×{{ $r->open_count }}</span>@endif</td>
            <td class="small">{{ $r->first_clicked_at?->format('M j, H:i') ?? '—' }}@if ($r->click_count > 1) <span class="text-muted">×{{ $r->click_count }}</span>@endif</td>
            <td class="small text-muted" style="max-width:260px">{{ \Illuminate\Support\Str::limit($r->bounce_reason ?: $r->failure_reason, 90) }}</td>
        </tr>
    @empty
        <tr><td colspan="8"><x-empty-state icon="bi-envelope" title="No recipients match">Adjust the filters.</x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $recipients->links() }}</div>
@endsection

@push('scripts')
<script>
(function () {
    const t = @json($totals);
    const r = @json($rates);
    new Chart(document.getElementById('funnel'), {
        type: 'bar',
        data: { labels: ['Sent', 'Delivered', 'Opened', 'Clicked', 'Bounced', 'Failed', 'Complaints', 'Unsubscribed'],
            datasets: [{ data: [t.sent, t.delivered, t.opened, t.clicked, t.bounced, t.failed, t.complained, t.unsubscribed],
                backgroundColor: ['#d9d5ff', '#a79fff', '#5b4cf5', '#2d1fb3', '#ef4444', '#f59e0b', '#f97316', '#9ca3af'], borderRadius: 8 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#eef0f6' } }, x: { grid: { display: false } } } },
    });
    new Chart(document.getElementById('rates'), {
        type: 'bar',
        data: { labels: ['Delivery', 'Open', 'Click', 'Bounce', 'Unsub.'],
            datasets: [{ data: [r.delivery, r.open, r.click, r.bounce, r.unsubscribe], backgroundColor: ['#16a34a', '#5b4cf5', '#2d1fb3', '#ef4444', '#9ca3af'], borderRadius: 8 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.parsed.x + '%' } } }, scales: { x: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + '%' }, grid: { color: '#eef0f6' } }, y: { grid: { display: false } } } },
    });
})();
</script>
@endpush
