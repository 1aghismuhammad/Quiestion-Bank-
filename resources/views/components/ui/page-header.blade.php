<header {{ $attributes->merge(['class' => 'ui-page-header flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6']) }}>
    <div>
        @isset($back)
            <div class="mb-2">{{ $back }}</div>
        @endisset

        <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-800 tracking-tight">{{ $slot }}</h1>

        @isset($supporting)
            <p class="muted text-sm text-slate-500 mt-1 font-normal">{{ $supporting }}</p>
        @endisset

        @isset($status)
            <div class="mt-2">{{ $status }}</div>
        @endisset
    </div>

    @isset($actions)
        <div class="ui-page-header-actions flex flex-wrap items-center gap-3">{{ $actions }}</div>
    @endisset
</header>
