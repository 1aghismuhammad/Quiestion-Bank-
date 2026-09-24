@php
    $latest = $latestBlueprintImport;
    $extractionLabel = match ($latest?->status->value) {
        'pending' => 'Menunggu ekstraksi',
        'processing' => 'Sedang diekstraksi',
        'extracted' => 'Ekstraksi selesai',
        'failed' => 'Ekstraksi gagal',
        default => 'Status tidak dikenali',
    };

    $interpretation = $latest?->interpretation_status;
    $interpretationLabel = match ($interpretation?->value) {
        null => 'Belum diinterpretasi',
        'queued' => 'Interpretasi mengantri',
        'processing' => 'Interpretasi diproses',
        'review_ready' => 'Siap ditinjau',
        'failed' => 'Interpretasi gagal',
        default => 'Status tidak dikenali',
    };
@endphp

<div class="card" style="margin-bottom: 20px;">
    <h2>Impor Kisi-kisi</h2>
    <p class="muted">Lihat kisi-kisi DOCX yang pernah diunggah untuk materi ini.</p>

    @if ($latest === null)
        <p class="muted">Belum ada kisi-kisi DOCX yang diunggah.</p>
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

    <div class="actions" style="margin-top: 12px;">
        <a class="button button-secondary" href="{{ route('materials.blueprints.index', $material) }}">Buka Halaman Kisi-kisi</a>
    </div>
</div>
