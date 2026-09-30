<div {{ $attributes->merge(['class' => 'ui-panel']) }}>
    <p>{{ $slot }}</p>
    @isset($action)
        <div class="action-stack">{{ $action }}</div>
    @endisset
</div>
