@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $class = 'ui-button ui-button-'.$variant;
@endphp

@if ($href)
    <a
        @if (! $disabled) href="{{ $href }}" @endif
        @if ($disabled) aria-disabled="true" @endif
        {{ $attributes->merge(['class' => $class]) }}
    >{{ $slot }}</a>
@else
    <button
        type="{{ $type }}"
        @disabled($disabled)
        {{ $attributes->merge(['class' => $class]) }}
    >{{ $slot }}</button>
@endif
