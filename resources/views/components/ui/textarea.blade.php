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
    <label class="label" for="{{ $fieldId }}">{{ $label }}</label>
    <textarea
        {{ $attributes->merge([
            'class' => 'ui-input',
            'name' => $name,
            'id' => $fieldId,
        ]) }}
        @if ($error !== '')
            aria-invalid="true"
            aria-describedby="{{ $errorId }}"
        @endif
    >{{ $value }}</textarea>
    @if ($error !== '')
        <div class="error-text" id="{{ $errorId }}">{{ $error }}</div>
    @endif
</div>
