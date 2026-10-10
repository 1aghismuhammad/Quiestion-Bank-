@props([
    'name',
    'id' => null,
    'value' => null,
    'label',
])

@php
    $fieldId = $id ?: $name;
    $error = $errors->first($name);
    $errorId = $fieldId.'-error';
@endphp

<div>
    <label class="label block mb-2 text-sm font-semibold text-slate-700" for="{{ $fieldId }}">{{ $label }}</label>
    <textarea
        {{ $attributes->merge([
            'class' => 'ui-input w-full bg-white/50 backdrop-blur-md border border-white/60 focus:border-purple-400 focus:bg-white/80 focus:ring-2 focus:ring-purple-400/30 rounded-xl px-4 py-2.5 text-slate-800 transition-all duration-200 outline-hidden placeholder:text-slate-400',
            'name' => $name,
            'id' => $fieldId,
        ]) }}
        @if ($error !== '')
            aria-invalid="true"
            aria-describedby="{{ $errorId }}"
        @endif
    >{{ $value }}</textarea>
    @if ($error !== '')
        <div class="error-text text-xs text-rose-500 mt-1.5 font-medium" id="{{ $errorId }}">{{ $error }}</div>
    @endif
</div>
