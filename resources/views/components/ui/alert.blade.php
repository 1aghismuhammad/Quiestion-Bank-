@props([
    'variant' => 'info',
])

@php
    $role = $variant === 'danger' ? 'alert' : 'status';
    $variantClass = match($variant) {
        'success' => 'bg-emerald-500/10 border-emerald-500/20 text-emerald-800',
        'danger' => 'bg-rose-500/10 border-rose-500/20 text-rose-800',
        'warning' => 'bg-amber-500/10 border-amber-500/20 text-amber-800',
        default => 'bg-indigo-500/10 border-indigo-500/20 text-indigo-800',
    };
    $class = 'ui-alert ui-alert-'.$variant.' backdrop-blur-md border rounded-2xl p-4 shadow-xs text-sm font-medium mb-5 '.$variantClass;
@endphp

<div {{ $attributes->merge(['class' => $class, 'role' => $role]) }}>
    {{ $slot }}
</div>
