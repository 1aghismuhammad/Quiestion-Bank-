@extends('layouts.app')

@section('title', 'Riwayat impor kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke materi</a>
    </div>

    <p class="muted">IMPOR KISI-KISI</p>
    <h1>Riwayat impor — {{ $material->title }}</h1>

    <div class="card" style="margin-top: 16px;">
        @if ($imports->isEmpty())
            <p class="muted">Belum ada impor kisi-kisi.</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Ekstraksi</th>
                        <th>Interpretasi</th>
                        <th>Dibuat</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($imports as $import)
                        @php
                            $extractionLabel = match ($import->status->value) {
                                'pending' => 'Menunggu',
                                'processing' => 'Diproses',
                                'extracted' => 'Selesai',
                                'failed' => 'Gagal',
                                default => $import->status->value,
                            };
                            $interpretationLabel = match ($import->interpretation_status?->value) {
                                null => 'Belum diinterpretasi',
                                'queued' => 'Mengantri',
                                'processing' => 'Diproses',
                                'review_ready' => 'Siap ditinjau',
                                'failed' => 'Gagal',
                                default => $import->interpretation_status?->value ?? 'Belum diinterpretasi',
                            };
                        @endphp
                        <tr>
                            <td>{{ $import->original_file_name }}</td>
                            <td>{{ $extractionLabel }}</td>
                            <td>{{ $interpretationLabel }}</td>
                            <td class="muted">{{ $import->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td>
                                <a href="{{ route('materials.blueprint-imports.show', [$material, $import]) }}">Tinjau</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($imports->hasPages())
                <div class="actions" style="margin-top: 16px;">
                    @if ($imports->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <a class="button button-secondary" href="{{ $imports->previousPageUrl() }}">Sebelumnya</a>
                    @endif

                    @if ($imports->hasMorePages())
                        <a class="button button-secondary" href="{{ $imports->nextPageUrl() }}">Berikutnya</a>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endsection
