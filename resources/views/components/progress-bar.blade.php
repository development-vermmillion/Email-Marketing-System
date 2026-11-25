@props(['value' => 0, 'large' => false, 'field' => null])
<div class="progress {{ $large ? 'lg' : '' }}" role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100">
    <div class="progress-bar" @if($field) data-field="{{ $field }}" @endif style="width: {{ $value }}%"></div>
</div>
