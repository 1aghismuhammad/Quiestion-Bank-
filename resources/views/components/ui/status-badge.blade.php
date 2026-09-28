@props([
    'variant' => 'neutral',
])

<span {{ $attributes->merge(['class' => 'ui-badge ui-badge-'.$variant]) }}>{{ $slot }}</span>
