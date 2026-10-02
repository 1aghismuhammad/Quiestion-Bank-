@extends('layouts.app')

@section('title', 'Riwayat impor')

@section('content')
    <div class="blueprint-import-history-page">
        <a class="blueprint-import-history-back" href="{{ route('materials.blueprints.index', $material) }}">
            <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            <span>Kembali ke kisi-kisi</span>
        </a>

        <x-ui.page-header>
            Riwayat impor
            <x-slot:supporting>
                Kisi-kisi DOCX yang pernah diunggah untuk materi ini.
            </x-slot:supporting>
        </x-ui.page-header>

    @if ($imports->isEmpty())
        <div class="blueprint-import-history-empty">
            <x-ui.empty-state>Belum ada impor.</x-ui.empty-state>
            <p class="muted">Kisi-kisi DOCX yang diunggah untuk materi ini akan muncul di sini.</p>
        </div>
    @else
        <div class="blueprint-import-history-surface responsive-table table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Berkas</th>
                        <th>Ekstraksi</th>
                        <th>Interpretasi</th>
                        <th>Pencocokan</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($imports as $import)
                        @php
                            $extractionLabel = match ($import->status->value) {
                                'pending' => 'Menunggu ekstraksi',
                                'processing' => 'Sedang diekstraksi',
                                'extracted' => 'Ekstraksi selesai',
                                'failed' => 'Ekstraksi gagal',
                                default => 'Status tidak dikenali',
                            };
                            $interpretationLabel = match ($import->interpretation_status?->value) {
                                null => 'Belum diinterpretasi',
                                'queued' => 'Interpretasi mengantri',
                                'processing' => 'Interpretasi diproses',
                                'review_ready' => 'Siap ditinjau',
                                'failed' => 'Interpretasi gagal',
                                default => 'Status tidak dikenali',
                            };
                        @endphp
                        <tr>
                            <td><span class="blueprint-import-history-name">{{ $import->original_file_name }}</span></td>
                            <td><span class="blueprint-import-history-status">{{ $extractionLabel }}</span></td>
                            <td><span class="blueprint-import-history-status">{{ $interpretationLabel }}</span></td>
                            <td><span class="blueprint-import-history-status">@include('materials.blueprint-imports._grounding-label', ['status' => $import->grounding_status?->value])</span></td>
                            <td class="muted">{{ $import->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td><a class="blueprint-import-history-review" href="{{ route('materials.blueprint-imports.show', [$material, $import]) }}">Tinjau</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="responsive-summary">
            @foreach ($imports as $import)
                @php
                    $extractionLabel = match ($import->status->value) {
                        'pending' => 'Menunggu ekstraksi',
                        'processing' => 'Sedang diekstraksi',
                        'extracted' => 'Ekstraksi selesai',
                        'failed' => 'Ekstraksi gagal',
                        default => 'Status tidak dikenali',
                    };
                    $interpretationLabel = match ($import->interpretation_status?->value) {
                        null => 'Belum diinterpretasi',
                        'queued' => 'Interpretasi mengantri',
                        'processing' => 'Interpretasi diproses',
                        'review_ready' => 'Siap ditinjau',
                        'failed' => 'Interpretasi gagal',
                        default => 'Status tidak dikenali',
                    };
                @endphp
                <article class="summary-row blueprint-import-history-card">
                    <strong class="blueprint-import-history-name">{{ $import->original_file_name }}</strong>
                    <p><span>Ekstraksi</span> {{ $extractionLabel }}</p>
                    <p><span>Interpretasi</span> {{ $interpretationLabel }}</p>
                    <p><span>Pencocokan</span> @include('materials.blueprint-imports._grounding-label', ['status' => $import->grounding_status?->value])</p>
                    <p class="muted">Dibuat {{ $import->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                    <a class="blueprint-import-history-review" href="{{ route('materials.blueprint-imports.show', [$material, $import]) }}">Tinjau</a>
                </article>
            @endforeach
        </div>

        @if ($imports->hasPages())
            <nav class="blueprint-import-history-pages" aria-label="Halaman riwayat impor">
                @if ($imports->onFirstPage())
                    <span class="muted">Sebelumnya</span>
                @else
                    <x-ui.button variant="secondary" href="{{ $imports->previousPageUrl() }}">Sebelumnya</x-ui.button>
                @endif
                @if ($imports->hasMorePages())
                    <x-ui.button variant="secondary" href="{{ $imports->nextPageUrl() }}">Berikutnya</x-ui.button>
                @else
                    <span class="muted">Berikutnya</span>
                @endif
            </nav>
        @endif
    @endif
    </div>
@endsection
