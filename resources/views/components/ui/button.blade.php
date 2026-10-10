@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    $baseClass = 'ui-button ui-button-'.$variant.' inline-flex items-center justify-center transition-all duration-300 cursor-pointer disabled:cursor-not-allowed disabled:opacity-50';
    $variantClass = match($variant) {
        'primary' => 'px-6 py-2 rounded-full text-white bg-gradient-to-r from-purple-500 to-pink-500 font-semibold shadow-md hover:shadow-lg hover:-translate-y-0.5 active:translate-y-0',
        'secondary' => 'px-6 py-2 rounded-full bg-white/50 text-slate-700 font-medium hover:bg-white/80 border border-white/50 shadow-xs hover:-translate-y-0.5 active:translate-y-0',
        'tertiary' => 'px-4 py-2 rounded-full text-purple-600 font-medium hover:bg-white/40',
        'danger' => 'px-6 py-2 rounded-full bg-rose-500 hover:bg-rose-600 text-white font-semibold shadow-sm hover:shadow-md hover:-translate-y-0.5 active:translate-y-0',
        default => 'px-6 py-2 rounded-full text-white bg-gradient-to-r from-purple-500 to-pink-500 font-semibold shadow-md hover:shadow-lg hover:-translate-y-0.5 active:translate-y-0',
    };
    $class = $baseClass . ' ' . $variantClass;
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
