@props([
    'variant' => 'info',
])

@php
    $role = $variant === 'danger' ? 'alert' : 'status';
@endphp

<div {{ $attributes->merge(['class' => 'ui-alert ui-alert-'.$variant, 'role' => $role]) }}>
    {{ $slot }}
</div>
