@props(['label', 'value', 'icon' => 'bi-circle', 'sub' => null, 'field' => null])
<div class="cardx stat h-100">
    <div class="label"><span class="icon"><i class="bi {{ $icon }}"></i></span> {{ $label }}</div>
    <div class="value-row">
        <span class="value" @if($field) data-field="{{ $field }}" @endif>{{ is_numeric($value) ? number_format($value) : $value }}</span>
        @if ($sub)<span class="sub">{{ $sub }}</span>@endif
    </div>
</div>
