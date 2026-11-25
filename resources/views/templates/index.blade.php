@extends('layouts.app')
@section('title', 'Email Templates')
@section('content')
<div class="page-head">
    <div><h2>Email templates</h2><p>Reusable, responsive HTML layouts. Use <code>@{{name}}</code>, <code>@{{email}}</code>, <code>@{{company}}</code> and <code>@{{unsubscribe_url}}</code> to personalise.</p></div>
    <a href="{{ route('templates.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New template</a>
</div>
<div class="table-wrap"><div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Name</th><th>Default subject</th><th>Used by</th><th>Updated</th><th></th></tr></thead>
    <tbody>
    @forelse ($templates as $t)
        <tr>
            <td><a class="fw-semibold text-dark" href="{{ route('templates.edit', $t) }}">{{ $t->name }}</a></td>
            <td class="text-muted">{{ $t->default_subject ?: '—' }}</td>
            <td>{{ $t->campaigns_count }} campaign(s)</td>
            <td class="small text-muted">{{ $t->updated_at->diffForHumans() }}</td>
            <td class="text-end row-actions">
                <a href="{{ route('templates.edit', $t) }}" class="btn btn-light"><i class="bi bi-pencil"></i> Edit</a>
                <form method="POST" action="{{ route('templates.duplicate', $t) }}" class="d-inline">@csrf<button class="btn btn-light" title="Duplicate"><i class="bi bi-copy"></i></button></form>
                <form method="POST" action="{{ route('templates.destroy', $t) }}" class="d-inline" data-confirm="Delete the template “{{ $t->name }}”?" data-confirm-button="Delete">@csrf @method('DELETE')<button class="btn btn-light text-danger" title="Delete"><i class="bi bi-trash3"></i></button></form>
            </td>
        </tr>
    @empty
        <tr><td colspan="5"><x-empty-state icon="bi-file-earmark-code" title="No templates yet">Create a template to use in campaigns.</x-empty-state></td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $templates->links() }}</div>
@endsection
