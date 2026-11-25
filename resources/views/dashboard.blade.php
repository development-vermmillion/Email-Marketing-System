@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="page-head">
    <div>
        <h2>Welcome back, {{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}!</h2>
        <p>Here's what's happening across your campaigns.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('imports.create') }}" class="btn btn-light"><i class="bi bi-upload me-1"></i> Import contacts</a>
        <a href="{{ route('campaigns.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New campaign</a>
    </div>
</div>

@php
    $pct = fn ($n, $d) => $d > 0 ? number_format($n / $d * 100, 1).'%' : '—';
    $base = $stats['delivered'] > 0 ? $stats['delivered'] : $stats['sent'];
@endphp
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
    <div class="col"><x-stat-card label="Total audiences" :value="$stats['audiences']" icon="bi-people" /></div>
    <div class="col"><x-stat-card label="Active subscribers" :value="$stats['active_subscribers']" icon="bi-person-check" /></div>
    <div class="col"><x-stat-card label="Total campaigns" :value="$stats['campaigns']" icon="bi-send" /></div>
    <div class="col"><x-stat-card label="Emails sent" :value="$stats['sent']" icon="bi-envelope-arrow-up" sub="accepted by SMTP" /></div>
    <div class="col"><x-stat-card label="Delivered" :value="$stats['delivered']" icon="bi-envelope-check" :sub="$pct($stats['delivered'], $stats['sent'])" /></div>
    <div class="col"><x-stat-card label="Opened" :value="$stats['opened']" icon="bi-envelope-open" :sub="$pct($stats['opened'], $base).' · approx.'" /></div>
    <div class="col"><x-stat-card label="Clicked" :value="$stats['clicked']" icon="bi-cursor" :sub="$pct($stats['clicked'], $base)" /></div>
    <div class="col"><x-stat-card label="Bounced" :value="$stats['bounced']" icon="bi-arrow-return-left" :sub="$pct($stats['bounced'], $stats['sent'])" /></div>
    <div class="col"><x-stat-card label="Failed" :value="$stats['failed']" icon="bi-x-octagon" /></div>
    <div class="col"><x-stat-card label="Unsubscribed" :value="$stats['unsubscribed']" icon="bi-person-dash" /></div>
</div>
<p class="text-muted small mb-4"><i class="bi bi-info-circle"></i> "Sent" means the SMTP relay accepted the message. Delivered, bounced and complaint numbers come from your provider's webhooks (SMTP Settings). Open counts are approximate: image blocking and mail-privacy features distort them.</p>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="cardx h-100">
            <div class="cardx-head"><h3>Activity<small>Last 30 days</small></h3></div>
            <div class="cardx-body"><div class="chart-box"><canvas id="dailyChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="cardx h-100">
            <div class="cardx-head"><h3>Delivery outcomes<small>All campaigns</small></h3></div>
            <div class="cardx-body"><div class="chart-box"><canvas id="outcomeChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-5">
        <div class="cardx h-100">
            <div class="cardx-head"><h3>Campaign performance<small>Latest campaigns</small></h3></div>
            <div class="cardx-body"><div class="chart-box sm"><canvas id="campaignChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="cardx h-100">
            <div class="cardx-head"><h3>Recent campaigns</h3><a href="{{ route('campaigns.index') }}">View all</a></div>
            <div class="cardx-body pt-2">
                @forelse ($stats['recent_campaigns'] as $c)
                    @if ($loop->first)
                    <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Opens</th></tr></thead><tbody>
                    @endif
                    <tr>
                        <td><a href="{{ route('campaigns.show', $c) }}" class="fw-semibold text-dark">{{ \Illuminate\Support\Str::limit($c->name, 32) }}</a></td>
                        <td><x-badge :color="$c->status->badge()">{{ $c->status->label() }}</x-badge></td>
                        <td>{{ number_format($c->sent_count) }}</td>
                        <td>{{ number_format($c->opened_count) }}</td>
                    </tr>
                    @if ($loop->last)</tbody></table></div>@endif
                @empty
                    <x-empty-state icon="bi-send" title="No campaigns yet">Create your first campaign to see results here.</x-empty-state>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const charts = @json($stats['charts']);
    const grid = { color: '#eef0f6' };
    const base = { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } } };

    new Chart(document.getElementById('dailyChart'), {
        type: 'line',
        data: { labels: charts.daily.labels, datasets: [
            { label: 'Sent', data: charts.daily.sent, borderColor: '#5b4cf5', backgroundColor: 'rgba(91,76,245,.10)', fill: true, tension: .35, pointRadius: 0 },
            { label: 'Opened', data: charts.daily.opened, borderColor: '#16a34a', tension: .35, pointRadius: 0 },
            { label: 'Clicked', data: charts.daily.clicked, borderColor: '#f59e0b', tension: .35, pointRadius: 0 },
        ]},
        options: { ...base, interaction: { mode: 'index', intersect: false }, scales: { y: { beginAtZero: true, grid }, x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } } } },
    });

    new Chart(document.getElementById('outcomeChart'), {
        type: 'doughnut',
        data: { labels: charts.outcomes.labels, datasets: [{ data: charts.outcomes.data, backgroundColor: ['#5b4cf5', '#ef4444', '#f59e0b', '#9ca3af'], borderWidth: 0 }] },
        options: { ...base, cutout: '68%' },
    });

    new Chart(document.getElementById('campaignChart'), {
        type: 'bar',
        data: { labels: charts.campaigns.labels, datasets: [
            { label: 'Sent', data: charts.campaigns.sent, backgroundColor: '#d9d5ff', borderRadius: 6 },
            { label: 'Delivered', data: charts.campaigns.delivered, backgroundColor: '#a79fff', borderRadius: 6 },
            { label: 'Opened', data: charts.campaigns.opened, backgroundColor: '#5b4cf5', borderRadius: 6 },
            { label: 'Clicked', data: charts.campaigns.clicked, backgroundColor: '#2d1fb3', borderRadius: 6 },
        ]},
        options: { ...base, scales: { y: { beginAtZero: true, grid }, x: { grid: { display: false } } } },
    });
})();
</script>
@endpush
