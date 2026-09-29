@props([
    'name',
    'id' => null,
    'type' => 'text',
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
    <input
        {{ $attributes->merge([
            'class' => 'ui-input',
            'name' => $name,
            'id' => $fieldId,
            'type' => $type,
            'value' => $value,
        ]) }}
        @if ($error !== '')
            aria-invalid="true"
            aria-describedby="{{ $errorId }}"
        @endif
    >
    @if ($error !== '')
        <div class="error-text" id="{{ $errorId }}">{{ $error }}</div>
    @endif
</div>
