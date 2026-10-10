<div {{ $attributes->merge(['class' => 'ui-panel bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-2xl p-8 text-center']) }}>
    <p class="text-slate-600 mb-4">{{ $slot }}</p>
    @isset($action)
        <div class="action-stack flex items-center justify-center gap-3">{{ $action }}</div>
    @endisset
</div>
