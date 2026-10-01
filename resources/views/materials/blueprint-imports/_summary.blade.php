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

    $grounding = $latest?->grounding_status;
    $groundingLabel = match ($grounding?->value) {
        null => 'Belum dicocokkan',
        'queued' => 'Menunggu pencocokan',
        'processing' => 'Sedang dicocokkan',
        'ready' => 'Pencocokan selesai',
        'failed' => 'Pencocokan gagal',
        default => 'Status tidak dikenali',
    };
@endphp

<section class="material-detail-section material-detail-import">
    <div class="material-detail-section-header">
        <svg aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
        <h2>Kisi-kisi terbaru</h2>
    </div>

    <div class="material-detail-import-body">
        @if ($latest === null)
            <p class="muted">Belum ada kisi-kisi DOCX yang diunggah.</p>
        @else
            <p style="margin-bottom: 12px; font-weight: 650; word-break: break-all;">File: {{ $latest->original_file_name }}</p>
            <x-ui.import-progress
                :extraction-label="$extractionLabel"
                extraction-variant="neutral"
                :interpretation-label="$interpretationLabel"
                interpretation-variant="neutral"
                :grounding-label="$groundingLabel"
                grounding-variant="neutral"
            />
            <p class="muted" style="margin-top: 12px;">
                Dibuat {{ $latest->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
            </p>

            <div class="action-stack" style="margin-top: 16px;">
                <a class="materials-button-secondary" href="{{ route('materials.blueprint-imports.show', [$material, $latest]) }}" style="width: 100%;">
                    Tinjau hasil interpretasi
                </a>
                <a class="materials-button-secondary" href="{{ route('materials.blueprint-imports.index', $material) }}" style="width: 100%;">
                    Lihat Riwayat Impor
                </a>
            </div>
        @endif

        <div class="action-stack" style="margin-top: 16px;">
            <a class="materials-button-secondary" href="{{ route('materials.blueprints.index', $material) }}" style="width: 100%;">Buka Halaman Kisi-kisi</a>
        </div>
    </div>
</section>
