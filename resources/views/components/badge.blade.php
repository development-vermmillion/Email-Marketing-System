@props(['color' => 'secondary'])
<span {{ $attributes->merge(['class' => 'pill pill-'.$color]) }}>{{ $slot }}</span>
