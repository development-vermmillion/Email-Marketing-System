@extends('layouts.app')
@section('title', $template->exists ? 'Edit template' : 'New template')
@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.css">
@endpush
@section('content')
<div class="page-head">
    <div><h2>{{ $template->exists ? 'Edit template' : 'New template' }}</h2><p>Edit the HTML on the left; the preview on the right is rendered by the server with sample data, exactly as recipients will see it.</p></div>
    <a href="{{ route('templates.index') }}" class="btn btn-light">Back</a>
</div>

<form method="POST" action="{{ $template->exists ? route('templates.update', $template) : route('templates.store') }}" id="tplForm" data-no-loading>
    @csrf
    @if ($template->exists) @method('PUT') @endif
    <div class="cardx mb-3"><div class="cardx-body"><div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label">Template name</label><input name="name" class="form-control" required maxlength="255" value="{{ old('name', $template->name) }}"></div>
        <div class="col-md-5"><label class="form-label">Default subject</label><input name="default_subject" class="form-control" maxlength="255" value="{{ old('default_subject', $template->default_subject) }}" placeholder="Pre-fills the campaign subject"></div>
        <div class="col-md-3 d-flex gap-2 justify-content-md-end">
            @if ($template->exists)<button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#tplTestModal"><i class="bi bi-envelope-paper me-1"></i>Test email</button>@endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save</button>
        </div>
    </div></div></div>

    <div class="editor-grid">
        <div class="cardx"><div class="cardx-head">
            <h3>HTML source</h3>
            <div class="d-flex gap-1 flex-wrap">
                @foreach (['name', 'first_name', 'email', 'company', 'phone', 'unsubscribe_url'] as $v)
                    @php $tag = '{'.'{'.$v.'}'.'}'; @endphp
                    <button type="button" class="btn btn-sm btn-light insert-var" data-var="{{ $tag }}">{{ $tag }}</button>
                @endforeach
            </div></div>
            <div class="cardx-body"><textarea id="html_content" name="html_content" required>{{ old('html_content', $template->html_content) }}</textarea>
            <div class="form-text mt-2">Contact values are HTML-escaped automatically. If <code>@{{unsubscribe_url}}</code> is missing, an unsubscribe footer is added to every email.</div></div>
        </div>
        <div class="cardx"><div class="cardx-head">
            <h3>Live preview <small id="previewState">Updating…</small></h3>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary active" data-device="desktop"><i class="bi bi-display"></i> Desktop</button>
                <button type="button" class="btn btn-outline-primary" data-device="mobile"><i class="bi bi-phone"></i> Mobile</button>
            </div></div>
            <div class="cardx-body"><div class="preview-frame-wrap" id="previewWrap"><iframe sandbox title="Email preview" id="previewFrame"></iframe></div></div>
        </div>
    </div>
</form>

@if ($template->exists)
<div class="modal fade" id="tplTestModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Send a test email</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <label class="form-label" for="tplTestTo">Send to</label>
        <input id="tplTestTo" type="email" class="form-control" placeholder="you@example.com">
        <div class="form-text">Sends the template as currently saved, with sample personalisation.</div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Close</button>
        <button class="btn btn-primary" data-ajax-post="{{ route('templates.test', $template) }}" data-email-input="#tplTestTo"><i class="bi bi-send me-1"></i>Send test</button></div>
</div></div></div>
@endif
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/css/css.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script>
(function () {
    const editor = CodeMirror.fromTextArea(document.getElementById('html_content'), { mode: 'htmlmixed', lineNumbers: true, lineWrapping: true, indentUnit: 2 });
    const frame = document.getElementById('previewFrame'), state = document.getElementById('previewState');

    const render = async () => {
        state.textContent = 'Updating…';
        try {
            const res = await fetch('{{ route('templates.preview') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': EMS.csrf, 'Accept': 'text/html' },
                body: JSON.stringify({ html_content: editor.getValue() }),
            });
            frame.srcdoc = await res.text();
            state.textContent = 'Up to date';
        } catch (e) { state.textContent = 'Preview unavailable'; }
    };
    const debounced = EMS.debounce(render, 600);
    editor.on('change', () => { editor.save(); debounced(); });
    render();

    document.querySelectorAll('[data-device]').forEach((b) => b.addEventListener('click', () => {
        document.querySelectorAll('[data-device]').forEach((x) => x.classList.toggle('active', x === b));
        document.getElementById('previewWrap').classList.toggle('mobile', b.dataset.device === 'mobile');
    }));
    document.querySelectorAll('.insert-var').forEach((b) => b.addEventListener('click', () => { editor.replaceSelection(b.dataset.var); editor.focus(); }));
    document.getElementById('tplForm').addEventListener('submit', () => editor.save());
})();
</script>
@endpush
