@props([
    'variant' => 'neutral',
])

@php
    $variantClass = match($variant) {
        'neutral' => 'bg-white/60 text-slate-700 border border-white/60',
        'success' => 'bg-emerald-500/15 text-emerald-800 border border-emerald-500/20',
        'warning' => 'bg-amber-500/15 text-amber-800 border border-amber-500/20',
        'danger' => 'bg-rose-500/15 text-rose-800 border border-rose-500/20',
        'info' => 'bg-indigo-500/15 text-indigo-800 border border-indigo-500/20',
        'processing' => 'bg-purple-500/15 text-purple-800 border border-purple-500/20',
        default => 'bg-white/60 text-slate-700 border border-white/60',
    };
    $class = 'ui-badge ui-badge-'.$variant.' inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold backdrop-blur-md shadow-xs '.$variantClass;
@endphp

<span {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</span>
