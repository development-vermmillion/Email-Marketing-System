<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    {{-- CDN builds are pinned. For production you can self-host these files under public/vendor. --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/admin.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
@php
    $nav = [
        ['dashboard', 'Dashboard', 'bi-grid-1x2-fill', 'dashboard'],
        ['campaigns.index', 'Campaigns', 'bi-send', 'campaigns.*'],
        ['audiences.index', 'Audiences', 'bi-people', ['audiences.*', 'imports.*', 'validation.*']],
        ['groups.index', 'Audience Groups', 'bi-collection', 'groups.*'],
        ['templates.index', 'Email Templates', 'bi-file-earmark-code', 'templates.*'],
        ['reports.index', 'Email Reports', 'bi-bar-chart-line', 'reports.*'],
        ['suppression.index', 'Suppression List', 'bi-slash-circle', 'suppression.*'],
        ['smtp.index', 'SMTP Settings', 'bi-hdd-network', 'smtp.*'],
        ['activity.index', 'Activity Logs', 'bi-clock-history', 'activity.*'],
        ['settings.edit', 'System Settings', 'bi-gear', 'settings.*'],
    ];
@endphp
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a href="{{ route('dashboard') }}" class="brand"><span class="brand-mark"><i class="bi bi-envelope-paper-fill"></i></span> {{ config('app.name') }}</a>
        <ul class="nav-list">
            @foreach ($nav as [$route, $label, $icon, $match])
                <li><a href="{{ route($route) }}" class="{{ request()->routeIs($match) ? 'active' : '' }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a></li>
            @endforeach
        </ul>
        <div class="sidebar-user">
            <div class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
            <div class="flex-grow-1 text-truncate">
                <div class="fw-semibold text-truncate">{{ auth()->user()->name }}</div>
                <div class="text-muted small text-truncate">{{ auth()->user()->email }}</div>
            </div>
            <form method="POST" action="{{ route('logout') }}" data-no-loading>
                @csrf
                <button class="btn btn-sm btn-light" title="Sign out"><i class="bi bi-box-arrow-right"></i></button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="btn btn-light sidebar-toggle" type="button"><i class="bi bi-list"></i></button>
            <h1>@yield('title', 'Dashboard')</h1>
            <form class="search" method="GET" action="{{ route('audiences.index') }}" data-no-loading>
                <i class="bi bi-search"></i>
                <input type="search" name="q" class="form-control" placeholder="Search contacts…" value="{{ request()->routeIs('audiences.*') ? request('q') : '' }}">
            </form>
        </header>
        <main class="content">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-4">
                <h5 class="fw-semibold mb-2">Are you sure?</h5>
                <p class="text-muted mb-4" data-confirm-message></p>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" data-confirm-ok>Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="{{ asset('js/admin.js') }}"></script>
@stack('scripts')
</body>
</html>
