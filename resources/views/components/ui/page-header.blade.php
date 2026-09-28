<header {{ $attributes->merge(['class' => 'ui-page-header']) }}>
    <div>
        @isset($back)
            <div>{{ $back }}</div>
        @endisset

        <h1>{{ $slot }}</h1>

        @isset($supporting)
            <p class="muted">{{ $supporting }}</p>
        @endisset

        @isset($status)
            <div>{{ $status }}</div>
        @endisset
    </div>

    @isset($actions)
        <div class="ui-page-header-actions">{{ $actions }}</div>
    @endisset
</header>
