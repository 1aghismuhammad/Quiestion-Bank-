@props([
    'extractionLabel',
    'extractionVariant' => 'neutral',
    'interpretationLabel',
    'interpretationVariant' => 'neutral',
    'groundingLabel',
    'groundingVariant' => 'neutral',
])

<div {{ $attributes->merge(['class' => 'import-progress']) }}>
    <p><strong>Ekstraksi:</strong> <x-ui.status-badge :variant="$extractionVariant">{{ $extractionLabel }}</x-ui.status-badge></p>
    <p><strong>Interpretasi:</strong> <x-ui.status-badge :variant="$interpretationVariant">{{ $interpretationLabel }}</x-ui.status-badge></p>
    <p><strong>Pencocokan:</strong> <x-ui.status-badge :variant="$groundingVariant">{{ $groundingLabel }}</x-ui.status-badge></p>
</div>
