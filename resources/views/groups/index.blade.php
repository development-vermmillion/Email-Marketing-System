@extends('layouts.app')
@section('title', 'Audience Groups')
@section('content')
<div class="page-head">
    <div>
        <h2>Audience groups</h2>
        <p>A group is a collection of contacts you target together. A contact can belong to several groups and still receives a campaign only once.</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#groupModal" data-create-modal="#groupModal" data-action="{{ route('groups.store') }}" data-title="Create group"><i class="bi bi-plus-lg me-1"></i> Create</button>
        <a href="{{ route('imports.create') }}" class="btn btn-dark text-uppercase" style="font-family:var(--mono);letter-spacing:.04em;font-size:.8rem">Add contacts</a>
    </div>
</div>

<div class="table-wrap">
    <table class="table align-middle">
        <thead><tr><th style="width:200px">Type</th><th>Name</th><th class="text-end">Total</th><th style="width:60px"></th></tr></thead>
        <tbody>
            <tr>
                <td><span class="type-tag"><span class="dot" style="background:#9ca3af"></span> Global list</span></td>
                <td><a href="{{ route('audiences.index') }}" class="fw-semibold text-dark">All Contacts</a><div class="text-muted small">Every contact lives here. Contacts added to other lists are also part of All Contacts.</div></td>
                <td class="text-end mono">{{ number_format($totalContacts) }}</td>
                <td></td>
            </tr>
            @forelse ($groups as $g)
                <tr>
                    <td><span class="type-tag"><span class="dot" style="background:{{ $g->color }}"></span> List</span></td>
                    <td><a href="{{ route('audiences.index', ['group' => $g->id]) }}" class="fw-semibold text-dark">{{ $g->name }}</a>@if ($g->description)<div class="text-muted small">{{ $g->description }}</div>@endif</td>
                    <td class="text-end mono">{{ number_format($g->audiences_count) }}</td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('audiences.index', ['group' => $g->id]) }}"><i class="bi bi-people"></i>View contacts</a></li>
                                <li><button class="dropdown-item" data-edit-modal="#groupModal" data-title="Edit group" data-action="{{ route('groups.update', $g) }}" data-values="{{ json_encode(['name' => $g->name, 'description' => $g->description, 'color' => $g->color]) }}"><i class="bi bi-pencil"></i>Edit</button></li>
                                <li><form method="POST" action="{{ route('groups.destroy', $g) }}" data-confirm="Delete the group “{{ $g->name }}”? Its contacts are kept." data-confirm-button="Delete group">@csrf @method('DELETE')<button class="dropdown-item text-danger"><i class="bi bi-trash3"></i>Delete</button></form></li>
                            </ul>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4"><x-empty-state icon="bi-collection" title="No groups yet">Create a group to segment your audience.</x-empty-state></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="modal fade" id="groupModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST" action="{{ old('_edit_action') ?: route('groups.store') }}" data-no-loading>
        @csrf
        @if (old('_edit_action')) @method('PUT') @endif
        <input type="hidden" name="_edit_action" value="{{ old('_edit_action') }}">
        <div class="modal-header"><h5 class="modal-title" data-modal-title>Create group</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            @if ($errors->any())<div class="alert alert-danger py-2">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
            <div class="mb-3"><label class="form-label">Name *</label><input name="name" class="form-control" value="{{ old('name') }}" required maxlength="255"></div>
            <div class="mb-3"><label class="form-label">Description</label><input name="description" class="form-control" value="{{ old('description') }}" maxlength="255"></div>
            <div><label class="form-label">Colour</label><input type="color" name="color" class="form-control form-control-color" value="{{ old('color', '#5b4cf5') }}"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save group</button></div>
    </form>
</div></div></div>
@if ($errors->any())<span data-open-modal="#groupModal" hidden></span>@endif
@endsection
