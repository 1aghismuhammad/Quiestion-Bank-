@extends('layouts.app')

@section('title', 'Riwayat impor')

@section('content')
    <x-ui.page-header>
        Riwayat impor
        <x-slot:supporting>
            Kisi-kisi DOCX yang pernah diunggah untuk materi ini.
        </x-slot:supporting>
    </x-ui.page-header>

    <div class="action-stack" style="margin-bottom: 24px;">
        <x-ui.button variant="tertiary" href="{{ route('materials.blueprints.index', $material) }}">Kembali ke kisi-kisi</x-ui.button>
    </div>

    @if ($imports->isEmpty())
        <x-ui.empty-state>Belum ada impor.</x-ui.empty-state>
    @else
        <div class="responsive-table table-wrap">
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
                            <td>{{ $import->original_file_name }}</td>
                            <td>{{ $extractionLabel }}</td>
                            <td>{{ $interpretationLabel }}</td>
                            <td>@include('materials.blueprint-imports._grounding-label', ['status' => $import->grounding_status?->value])</td>
                            <td class="muted">{{ $import->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td><a href="{{ route('materials.blueprint-imports.show', [$material, $import]) }}">Tinjau</a></td>
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
                <article class="summary-row">
                    <strong>{{ $import->original_file_name }}</strong>
                    <p>Ekstraksi: {{ $extractionLabel }}</p>
                    <p>Interpretasi: {{ $interpretationLabel }}</p>
                    <p>Pencocokan: @include('materials.blueprint-imports._grounding-label', ['status' => $import->grounding_status?->value])</p>
                    <a href="{{ route('materials.blueprint-imports.show', [$material, $import]) }}">Tinjau</a>
                </article>
            @endforeach
        </div>

        @if ($imports->hasPages())
            <div class="action-stack" style="margin-top: 16px;">
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
            </div>
        @endif
    @endif
@endsection
