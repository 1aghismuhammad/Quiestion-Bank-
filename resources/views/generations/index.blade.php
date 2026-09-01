@php
    $statusLabels = [
        'queued' => 'Antrian',
        'processing' => 'Diproses',
        'completed' => 'Selesai',
        'failed' => 'Gagal',
        'cancelled' => 'Dibatalkan',
    ];
    $languageLabels = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];
@endphp

@extends('layouts.app')

@section('title', 'Riwayat generasi')

@section('content')
    <p class="muted">GENERATION HISTORY</p>
    <h1>Riwayat generasi</h1>

    @if ($generations->isEmpty())
        <div class="card">
            <p>Belum ada generasi soal.</p>
            <a class="button" href="{{ route('materials.index') }}">Pilih materi</a>
        </div>
    @else
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Materi</th>
                        <th>Jumlah soal</th>
                        <th>Bahasa</th>
                        <th>Antrian</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($generations as $generation)
                        <tr>
                            <td>
                                <a href="{{ route('generations.show', $generation) }}">
                                    {{ $statusLabels[$generation->generation_status->value] ?? $generation->generation_status->value }}
                                </a>
                                <span class="muted">({{ $generation->generation_status->value }})</span>
                            </td>
                            <td>{{ $generation->material?->title ?? 'Materi tidak tersedia' }}</td>
                            <td>{{ $generation->question_count }}</td>
                            <td>{{ $languageLabels[$generation->output_language?->value] ?? $generation->output_language?->value }}</td>
                            <td class="muted">{{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($generations->hasPages())
                <div class="actions" style="margin-top: 16px;">
                    @if ($generations->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <a class="button button-secondary" href="{{ $generations->previousPageUrl() }}">Sebelumnya</a>
                    @endif

                    @if ($generations->hasMorePages())
                        <a class="button button-secondary" href="{{ $generations->nextPageUrl() }}">Berikutnya</a>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        </div>
    @endif
@endsection
