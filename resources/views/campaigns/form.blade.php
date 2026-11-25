@extends('layouts.app')
@section('title', $campaign->exists ? 'Edit campaign' : 'New campaign')
@section('content')
@php
    $selectedGroups = collect(old('group_ids', $campaign->group_ids ?? []))->map(fn ($v) => (int) $v)->all();
    $val = fn ($key, $default = null) => old($key, $campaign->{$key} ?? $default);
@endphp
<div class="page-head">
    <div>
        <h2>{{ $campaign->exists ? 'Edit campaign' : 'New campaign' }}</h2>
        <p>Set up the message, pick who receives it, then save as a draft, schedule it, or send it now.</p>
    </div>
    <a href="{{ $campaign->exists ? route('campaigns.show', $campaign) : route('campaigns.index') }}" class="btn btn-light">Cancel</a>
</div>

<form method="POST" action="{{ $campaign->exists ? route('campaigns.update', $campaign) : route('campaigns.store') }}" data-no-loading>
    @csrf
    @if ($campaign->exists) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cardx mb-3">
                <div class="cardx-head"><h3>Message</h3></div>
                <div class="cardx-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="name">Campaign name</label>
                            <input id="name" name="name" class="form-control" value="{{ $val('name') }}" required maxlength="255" placeholder="Diwali offer – North region">
                            <div class="form-text">Internal only.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email_template_id">Email template</label>
                            <select id="email_template_id" name="email_template_id" class="form-select" required>
                                <option value="">Select a template…</option>
                                @foreach ($templates as $t)
                                    <option value="{{ $t->id }}" data-subject="{{ $t->default_subject }}" @selected((int) $val('email_template_id') === $t->id)>{{ $t->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text"><a href="{{ route('templates.create') }}" target="_blank">Create a template</a></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="subject">Email subject</label>
                            <input id="subject" name="subject" class="form-control" value="{{ $val('subject') }}" required maxlength="255" placeholder="Hi @{{first_name}}, our biggest sale starts today">
                            <div class="form-text">You can personalise with <code>@{{name}}</code>, <code>@{{first_name}}</code>, <code>@{{company}}</code>.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="from_name">Sender name</label>
                            <input id="from_name" name="from_name" class="form-control" value="{{ $val('from_name') }}" required maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="from_email">Sender email</label>
                            <input id="from_email" type="email" name="from_email" class="form-control" value="{{ $val('from_email') }}" required>
                            <div class="form-text">Must be a domain you have authenticated (SPF/DKIM) with your relay.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="reply_to_email">Reply-to email</label>
                            <input id="reply_to_email" type="email" name="reply_to_email" class="form-control" value="{{ $val('reply_to_email') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="cardx mb-3">
                <div class="cardx-head">
                    <h3>Audience<small>Pick one or more groups. A contact in several groups still receives the email only once.</small></h3>
                    <span class="pill pill-primary" id="estimate">Estimating…</span>
                </div>
                <div class="cardx-body">
                    @if ($groups->isEmpty())
                        <div class="text-muted">No groups yet. With no group selected, <strong>every active, subscribed contact</strong> receives the campaign. <a href="{{ route('groups.index') }}">Create groups</a> to segment.</div>
                    @else
                        <div class="row g-2">
                            @foreach ($groups as $g)
                                <div class="col-md-6 col-xl-4">
                                    <label class="d-flex align-items-center gap-2 border rounded-3 p-2 px-3" style="cursor:pointer">
                                        <input class="form-check-input mt-0 group-check" type="checkbox" name="group_ids[]" value="{{ $g->id }}" @checked(in_array($g->id, $selectedGroups, true))>
                                        <span class="dot" style="background: {{ $g->color }}"></span>
                                        <span class="flex-grow-1 text-truncate">{{ $g->name }}</span>
                                        <span class="text-muted small">{{ number_format($g->audiences_count) }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text mt-2">Leave all unchecked to send to every active, subscribed contact. Unsubscribed, bounced and suppressed addresses are always excluded.</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="cardx mb-3">
                <div class="cardx-head"><h3>Delivery</h3></div>
                <div class="cardx-body">
                    <div class="mb-3">
                        <label class="form-label" for="smtp_setting_id">SMTP configuration</label>
                        <select id="smtp_setting_id" name="smtp_setting_id" class="form-select">
                            <option value="">Use default active configuration</option>
                            @foreach ($smtpSettings as $s)
                                <option value="{{ $s->id }}" @selected((int) $val('smtp_setting_id') === $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="scheduled_at">Schedule for later</label>
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at" class="form-control" value="{{ $val('scheduled_at') ? \Illuminate\Support\Carbon::parse($val('scheduled_at'))->format('Y-m-d\TH:i') : '' }}">
                        <div class="form-text">Time zone: {{ $timezone }}</div>
                    </div>
                    <a class="small" data-bs-toggle="collapse" href="#limits"><i class="bi bi-sliders"></i> Sending controls</a>
                    <div class="collapse {{ collect(['rate_per_second', 'rate_per_minute', 'batch_size', 'max_attempts', 'retry_delay', 'daily_limit'])->contains(fn ($k) => $val($k)) ? 'show' : '' }}" id="limits">
                        <div class="row g-2 mt-1">
                            <div class="col-6"><label class="form-label">Emails / second</label><input type="number" min="1" name="rate_per_second" class="form-control" value="{{ $val('rate_per_second') }}" placeholder="{{ $defaults['rate_per_second'] }}"></div>
                            <div class="col-6"><label class="form-label">Emails / minute</label><input type="number" min="1" name="rate_per_minute" class="form-control" value="{{ $val('rate_per_minute') }}" placeholder="{{ $defaults['rate_per_minute'] }}"></div>
                            <div class="col-6"><label class="form-label">Batch size</label><input type="number" min="1" max="1000" name="batch_size" class="form-control" value="{{ $val('batch_size') }}" placeholder="{{ $defaults['batch_size'] }}"></div>
                            <div class="col-6"><label class="form-label">Max attempts</label><input type="number" min="1" max="10" name="max_attempts" class="form-control" value="{{ $val('max_attempts') }}" placeholder="{{ $defaults['max_attempts'] }}"></div>
                            <div class="col-6"><label class="form-label">Retry delay (s)</label><input type="number" min="30" name="retry_delay" class="form-control" value="{{ $val('retry_delay') }}" placeholder="{{ $defaults['retry_delay'] }}"></div>
                            <div class="col-6"><label class="form-label">Daily limit</label><input type="number" min="1" name="daily_limit" class="form-control" value="{{ $val('daily_limit') }}" placeholder="none"></div>
                        </div>
                        <div class="form-text mt-2">Blank = use the SMTP configuration's limits, then the system defaults. The strictest value always wins, so provider limits are respected.</div>
                    </div>
                </div>
            </div>

            <div class="cardx">
                <div class="cardx-body d-grid gap-2">
                    <button type="submit" name="action" value="draft" class="btn btn-light"><i class="bi bi-save me-1"></i> Save as draft</button>
                    <button type="submit" name="action" value="schedule" class="btn btn-outline-primary"><i class="bi bi-calendar-event me-1"></i> Schedule</button>
                    <button type="submit" name="action" value="send" class="btn btn-primary" data-confirm="Send this campaign now? Once sending starts, recipients and content are locked." data-confirm-button="Send now" data-confirm-class="btn-primary"><i class="bi bi-send me-1"></i> Send now</button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const badge = document.getElementById('estimate');
    const refresh = EMS.debounce(async () => {
        const params = new URLSearchParams();
        document.querySelectorAll('.group-check:checked').forEach((c) => params.append('group_ids[]', c.value));
        const d = await EMS.getJson('{{ route('campaigns.estimate') }}?' + params.toString());
        badge.textContent = d ? Number(d.count).toLocaleString() + ' eligible recipients' : 'Estimate unavailable';
    }, 250);
    document.querySelectorAll('.group-check').forEach((c) => c.addEventListener('change', refresh));
    refresh();

    // Pre-fill the subject from the template's default subject when it is still empty.
    const tpl = document.getElementById('email_template_id'), subject = document.getElementById('subject');
    tpl.addEventListener('change', () => {
        const s = tpl.selectedOptions[0]?.dataset.subject;
        if (s && !subject.value.trim()) subject.value = s;
    });
})();
</script>
@endpush
