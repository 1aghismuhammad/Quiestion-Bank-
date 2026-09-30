@props(['material'])

@php
    use App\Enums\ExtractionStatus;
    use App\Enums\MaterialStatus;

    if ($material->status === MaterialStatus::ARCHIVED) {
        $label = 'Diarsipkan';
        $variant = 'neutral';
    } else {
        [$label, $variant] = match ($material->extraction_status) {
            ExtractionStatus::PENDING => ['Menunggu ekstraksi', 'neutral'],
            ExtractionStatus::PROCESSING => ['Sedang diekstraksi', 'info'],
            ExtractionStatus::COMPLETED => ['Selesai', 'success'],
            ExtractionStatus::FAILED => ['Gagal ekstraksi', 'danger'],
            ExtractionStatus::NOT_REQUIRED => ['Tidak diperlukan', 'neutral'],
        };
    }
@endphp

<x-ui.status-badge :variant="$variant">{{ $label }}</x-ui.status-badge>
