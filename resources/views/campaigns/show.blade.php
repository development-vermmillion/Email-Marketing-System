@extends('layouts.app')
@section('title', $campaign->name)
@section('content')
@php
    $s = $campaign->status;
    $live = ! $campaign->trashed() && ($s->isActive() || $s->value === 'scheduled');
@endphp
<div class="page-head">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h2 class="mb-0">{{ $campaign->name }}</h2>
            <span class="pill pill-{{ $s->badge() }}" data-live-status>{{ $s->label() }}</span>
        </div>
        <p>{{ $campaign->subject }}</p>
        @if ($campaign->status_note)<p class="text-warning mt-1"><i class="bi bi-exclamation-triangle"></i> {{ $campaign->status_note }}</p>@endif
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if ($campaign->trashed())
            <form method="POST" action="{{ route('campaigns.restore', $campaign) }}">@csrf<button class="btn btn-primary"><i class="bi bi-arrow-counterclockwise me-1"></i> Restore</button></form>
        @else
            @if ($campaign->canEdit())<a href="{{ route('campaigns.edit', $campaign) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i> Edit</a>@endif
            @if ($campaign->canEdit())
                <form method="POST" action="{{ route('campaigns.send', $campaign) }}" data-confirm="Send this campaign now?" data-confirm-button="Send now" data-confirm-class="btn-primary">@csrf<button class="btn btn-primary"><i class="bi bi-send me-1"></i> Send now</button></form>
            @endif
            @if ($s->value === 'scheduled')
                <form method="POST" action="{{ route('campaigns.unschedule', $campaign) }}">@csrf<button class="btn btn-light">Unschedule</button></form>
            @endif
            @if (in_array($s->value, ['queued', 'sending']))
                <form method="POST" action="{{ route('campaigns.pause', $campaign) }}">@csrf<button class="btn btn-warning"><i class="bi bi-pause-fill me-1"></i> Pause</button></form>
            @endif
            @if ($s->value === 'paused')
                <form method="POST" action="{{ route('campaigns.resume', $campaign) }}">@csrf<button class="btn btn-primary"><i class="bi bi-play-fill me-1"></i> Resume</button></form>
            @endif
            @if (in_array($s->value, ['scheduled', 'queued', 'sending', 'paused']))
                <form method="POST" action="{{ route('campaigns.cancel', $campaign) }}" data-confirm="Cancel this campaign? Emails not yet sent will be skipped and cannot be resumed." data-confirm-button="Cancel campaign">@csrf<button class="btn btn-outline-danger">Cancel</button></form>
            @endif
            <div class="dropdown">
                <button class="btn btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#previewModal"><i class="bi bi-eye"></i>Preview email</button></li>
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#testModal"><i class="bi bi-envelope-paper"></i>Send test email</button></li>
                    <li><a class="dropdown-item" href="{{ route('reports.index', ['campaign_id' => $campaign->id]) }}"><i class="bi bi-bar-chart"></i>Full report</a></li>
                    <li><form method="POST" action="{{ route('campaigns.duplicate', $campaign) }}">@csrf<button class="dropdown-item"><i class="bi bi-copy"></i>Duplicate</button></form></li>
                    @unless ($s->isActive())
                    <li><hr class="dropdown-divider"></li>
                    <li><form method="POST" action="{{ route('campaigns.destroy', $campaign) }}" data-confirm="Move this campaign to the trash? You can restore it for {{ config('mailing.trash_retention_days') }} days." data-confirm-button="Move to trash">@csrf @method('DELETE')<button class="dropdown-item text-danger"><i class="bi bi-trash3"></i>Delete</button></form></li>
                    @endunless
                </ul>
            </div>
        @endif
    </div>
</div>

<div @if($live) data-progress-url="{{ route('campaigns.progress', $campaign) }}" data-reload-on-change="1" data-initial-status="{{ $s->value }}" @endif>
    <div class="cardx mb-3">
        <div class="cardx-body">
            <div class="d-flex justify-content-between mb-2">
                <span class="fw-semibold">Progress <span class="text-muted fw-normal" data-field="progress_text">{{ $campaign->progress }}%</span></span>
                <span class="text-muted small"><span data-field="queued_count">{{ number_format($campaign->queued_count) }}</span> waiting · <span data-field="total_recipients">{{ number_format($campaign->total_recipients) }}</span> total</span>
            </div>
            <x-progress-bar :value="$campaign->progress" :large="true" field="progress" />
            @if ($live)<div class="text-muted small mt-2"><i class="bi bi-arrow-repeat"></i> Updating live. Sending continues on the server even if you close this page.</div>@endif
        </div>
    </div>

    <div class="row row-cols-2 row-cols-md-4 row-cols-xl-5 g-3 mb-3">
        @foreach ([
            ['Recipients', 'total_recipients', 'bi-people', null],
            ['Waiting', 'queued_count', 'bi-hourglass-split', null],
            ['Sent', 'sent_count', 'bi-envelope-arrow-up', 'accepted by SMTP'],
            ['Delivered', 'delivered_count', 'bi-envelope-check', $campaign->delivery_rate.'%'],
            ['Opened', 'opened_count', 'bi-envelope-open', $campaign->open_rate.'% · approx.'],
            ['Clicked', 'clicked_count', 'bi-cursor', $campaign->click_rate.'%'],
            ['Bounced', 'bounced_count', 'bi-arrow-return-left', $campaign->bounce_rate.'%'],
            ['Failed', 'failed_count', 'bi-x-octagon', null],
            ['Complaints', 'complained_count', 'bi-flag', null],
            ['Unsubscribed', 'unsubscribed_count', 'bi-person-dash', $campaign->unsubscribe_rate.'%'],
        ] as [$label, $field, $icon, $sub])
            <div class="col">
                <div class="cardx stat h-100">
                    <div class="label"><span class="icon"><i class="bi {{ $icon }}"></i></span> {{ $label }}</div>
                    <div class="value-row"><span class="value" data-field="{{ $field }}">{{ number_format($campaign->{$field}) }}</span>@if($sub)<span class="sub">{{ $sub }}</span>@endif</div>
                </div>
            </div>
        @endforeach
    </div>
    @if ($campaign->skipped_count)
        <p class="text-muted small"><i class="bi bi-info-circle"></i> <span data-field="skipped_count">{{ number_format($campaign->skipped_count) }}</span> recipient(s) were skipped (deleted, unsubscribed or suppressed after the campaign was prepared, or cancelled).</p>
    @endif
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="cardx h-100">
            <div class="cardx-head"><h3>Funnel</h3></div>
            <div class="cardx-body"><div class="chart-box sm"><canvas id="funnel"></canvas></div>
                <p class="text-muted small mb-0 mt-2">Delivery, bounce and complaint figures need provider webhooks. Open tracking is approximate (image blocking, Apple Mail Privacy Protection and link scanners distort it).</p></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="cardx h-100">
            <div class="cardx-head"><h3>Details</h3></div>
            <div class="cardx-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted fw-normal">From</dt><dd class="col-7">{{ $campaign->from_name }} &lt;{{ $campaign->from_email }}&gt;</dd>
                    <dt class="col-5 text-muted fw-normal">Reply-to</dt><dd class="col-7">{{ $campaign->reply_to_email ?: '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Template</dt><dd class="col-7">{{ $campaign->template?->name }}</dd>
                    <dt class="col-5 text-muted fw-normal">SMTP</dt><dd class="col-7">{{ $campaign->smtpSetting?->name ?? 'Default configuration' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Audience</dt>
                    <dd class="col-7">@forelse ($groups as $g)<span class="pill pill-secondary me-1">{{ $g->name }}</span>@empty All active subscribers @endforelse</dd>
                    <dt class="col-5 text-muted fw-normal">Scheduled</dt><dd class="col-7">{{ $campaign->scheduled_at?->format('M j, Y H:i') ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Started</dt><dd class="col-7">{{ $campaign->started_at?->format('M j, Y H:i') ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Completed</dt><dd class="col-7">{{ $campaign->completed_at?->format('M j, Y H:i') ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Sending limits</dt>
                    <dd class="col-7">{{ $campaign->rate_per_second ?: 'default' }}/s · {{ $campaign->rate_per_minute ?: 'default' }}/min · batch {{ $campaign->batch_size ?: 'default' }}@if($campaign->daily_limit) · {{ number_format($campaign->daily_limit) }}/day @endif</dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="cardx">
            <div class="cardx-head"><h3>Activity</h3></div>
            <div class="cardx-body">
                @forelse ($campaign->activities as $a)
                    <div class="d-flex gap-3 py-2 border-bottom">
                        <span class="text-muted small" style="min-width:130px">{{ $a->created_at->format('M j, H:i:s') }}</span>
                        <span>{{ $a->description ?: $a->action }}</span>
                    </div>
                @empty
                    <span class="text-muted">No activity yet.</span>
                @endforelse
            </div>
        </div>
    </div>
</div>

@unless ($campaign->trashed())
<div class="modal fade" id="previewModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Email preview <small class="text-muted">(sample data)</small></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-0"><iframe sandbox title="Email preview" src="about:blank" data-src="{{ route('campaigns.preview', $campaign) }}" style="width:100%;height:70vh;border:0"></iframe></div>
</div></div></div>

<div class="modal fade" id="testModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Send a test email</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <label class="form-label" for="testTo">Send to</label>
        <input id="testTo" type="email" class="form-control" placeholder="you@example.com" required>
        <div class="form-text">Sent through your SMTP configuration with sample personalisation. Test emails are not counted in campaign statistics.</div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" data-ajax-post="{{ route('campaigns.test', $campaign) }}" data-email-input="#testTo"><i class="bi bi-send me-1"></i>Send test</button></div>
</div></div></div>
@endunless
@endsection

@push('scripts')
@php
    $funnel = [
        'sent_count' => $campaign->sent_count, 'delivered_count' => $campaign->delivered_count, 'opened_count' => $campaign->opened_count,
        'clicked_count' => $campaign->clicked_count, 'bounced_count' => $campaign->bounced_count, 'failed_count' => $campaign->failed_count,
        'unsubscribed_count' => $campaign->unsubscribed_count,
    ];
@endphp
<script>
(function () {
    const c = @json($funnel);
    new Chart(document.getElementById('funnel'), {
        type: 'bar',
        data: { labels: ['Sent', 'Delivered', 'Opened', 'Clicked', 'Bounced', 'Failed', 'Unsubscribed'],
            datasets: [{ data: [c.sent_count, c.delivered_count, c.opened_count, c.clicked_count, c.bounced_count, c.failed_count, c.unsubscribed_count],
                backgroundColor: ['#d9d5ff', '#a79fff', '#5b4cf5', '#2d1fb3', '#ef4444', '#f59e0b', '#9ca3af'], borderRadius: 8 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, grid: { color: '#eef0f6' } }, y: { grid: { display: false } } } },
    });
    const modal = document.getElementById('previewModal');
    modal?.addEventListener('show.bs.modal', () => { const f = modal.querySelector('iframe'); if (f.src === 'about:blank' || !f.dataset.loaded) { f.src = f.dataset.src; f.dataset.loaded = '1'; } });
})();
</script>
@endpush
