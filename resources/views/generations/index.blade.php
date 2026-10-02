@php
    $statusLabels = [
        'queued' => 'Menunggu diproses',
        'processing' => 'Sedang diproses',
        'completed' => 'Selesai',
        'failed' => 'Gagal',
        'cancelled' => 'Dibatalkan',
    ];
    $statusVariants = [
        'queued' => 'processing',
        'processing' => 'processing',
        'completed' => 'success',
        'failed' => 'danger',
        'cancelled' => 'neutral',
    ];
    $languageLabels = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];
@endphp

@extends('layouts.app')

@section('title', 'Pembuatan soal')

@section('content')
    <div class="legacy-generation-history-page">
        <x-ui.page-header>
            Pembuatan soal
            <x-slot:supporting>Riwayat pembuatan soal langsung dari materi.</x-slot:supporting>
        </x-ui.page-header>

        @if ($generations->isEmpty())
            <x-ui.empty-state>
                Belum ada pembuatan soal.
                <x-slot:action>
                    <x-ui.button href="{{ route('materials.index') }}">Pilih materi</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="legacy-generation-history-surface responsive-table table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Materi</th>
                            <th>Jumlah soal</th>
                            <th>Bahasa</th>
                            <th>Antrian</th>
                            <th>Buka</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($generations as $generation)
                            @php
                                $statusValue = $generation->generation_status->value;
                            @endphp
                            <tr>
                                <td>
                                    <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                        {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                                    </x-ui.status-badge>
                                </td>
                                <td class="legacy-generation-history-material">{{ $generation->material?->title ?? 'Materi tidak tersedia' }}</td>
                                <td>{{ $generation->question_count }}</td>
                                <td>{{ $languageLabels[$generation->output_language?->value] ?? 'Bahasa tidak dikenali' }}</td>
                                <td class="muted">{{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                                <td>
                                    <a class="legacy-generation-history-open" href="{{ route('generations.show', $generation) }}">Buka</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="responsive-summary">
                @foreach ($generations as $generation)
                    @php
                        $statusValue = $generation->generation_status->value;
                    @endphp
                    <article class="summary-row legacy-generation-history-card">
                        <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                            {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                        </x-ui.status-badge>
                        <strong class="legacy-generation-history-material">{{ $generation->material?->title ?? 'Materi tidak tersedia' }}</strong>
                        <p class="muted">{{ $generation->question_count }} soal · {{ $languageLabels[$generation->output_language?->value] ?? 'Bahasa tidak dikenali' }}</p>
                        <p class="muted">{{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                        <x-ui.button variant="secondary" href="{{ route('generations.show', $generation) }}">Buka</x-ui.button>
                    </article>
                @endforeach
            </div>

            @if ($generations->hasPages())
                <div class="action-stack legacy-generation-history-pages">
                    @if ($generations->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <x-ui.button variant="secondary" href="{{ $generations->previousPageUrl() }}">Sebelumnya</x-ui.button>
                    @endif

                    @if ($generations->hasMorePages())
                        <x-ui.button variant="secondary" href="{{ $generations->nextPageUrl() }}">Berikutnya</x-ui.button>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endsection
