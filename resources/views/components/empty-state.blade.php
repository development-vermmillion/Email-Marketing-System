@props(['icon' => 'bi-inbox', 'title' => 'Nothing here yet'])
<div class="empty">
    <i class="bi {{ $icon }}"></i>
    <div class="fw-semibold text-dark mb-1">{{ $title }}</div>
    <div>{{ $slot }}</div>
</div>
