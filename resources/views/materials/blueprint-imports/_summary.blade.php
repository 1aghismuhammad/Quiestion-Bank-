@php
    $latest = $latestBlueprintImport;
    $extractionLabel = match ($latest?->status->value) {
        'pending' => 'Menunggu ekstraksi',
        'processing' => 'Sedang diekstraksi',
        'extracted' => 'Ekstraksi selesai',
        'failed' => 'Ekstraksi gagal',
        default => $latest?->status->value,
    };

    $interpretation = $latest?->interpretation_status;
    $interpretationLabel = match ($interpretation?->value) {
        null => 'Belum diinterpretasi',
        'queued' => 'Interpretasi mengantri',
        'processing' => 'Interpretasi diproses',
        'review_ready' => 'Siap ditinjau',
        'failed' => 'Interpretasi gagal',
        default => $interpretation?->value ?? 'Belum diinterpretasi',
    };
@endphp

<div class="card" style="margin-bottom: 20px;">
    <h2>Impor kisi-kisi</h2>

    @if ($latest === null)
        <p class="muted">Belum ada impor kisi-kisi.</p>
    @else
        <p><strong>File:</strong> {{ $latest->original_file_name }}</p>
        <p>
            <strong>Ekstraksi:</strong>
            <span class="status">{{ $extractionLabel }}</span>
        </p>
        <p>
            <strong>Interpretasi:</strong>
            <span class="status">{{ $interpretationLabel }}</span>
        </p>
        <p class="muted">
            Dibuat
            {{ $latest->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
        </p>

        <div class="actions" style="margin-top: 12px;">
            <a class="button" href="{{ route('materials.blueprint-imports.show', [$material, $latest]) }}">
                Tinjau hasil interpretasi
            </a>
            <a class="button button-secondary" href="{{ route('materials.blueprint-imports.index', $material) }}">
                Lihat Riwayat Impor
            </a>
        </div>
    @endif
</div>
