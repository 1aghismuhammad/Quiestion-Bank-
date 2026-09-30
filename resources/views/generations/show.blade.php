@php
    $status = $generation->generation_status;
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
    $statusLabel = $statusLabels[$status->value] ?? 'Status tidak dikenali';
    $statusVariant = $statusVariants[$status->value] ?? 'neutral';
@endphp

@extends('layouts.app')

@section('title', 'Status generasi')

@section('content')
    <x-ui.page-header>
        Status generasi
        <x-slot:back>
            <x-ui.button variant="tertiary" href="{{ route('generations.index') }}">Pembuatan soal</x-ui.button>
            @if ($generation->material)
                <x-ui.button variant="tertiary" href="{{ route('materials.show', $generation->material) }}">Kembali ke materi</x-ui.button>
            @endif
        </x-slot:back>
        <x-slot:status>
            <x-ui.status-badge id="generation-status-label" :variant="$statusVariant" data-generation-status="{{ $status->value }}">{{ $statusLabel }}</x-ui.status-badge>
        </x-slot:status>
    </x-ui.page-header>

    @include('generations._quota', ['usage' => $usage])

    <x-ui.panel>
        <p aria-live="polite" id="generation-status-live">
            Status saat ini: {{ $statusLabel }}
        </p>
        <p><strong>Materi:</strong> {{ $generation->material?->title ?? 'Materi tidak tersedia' }}</p>
        <p><strong>Jenis asesmen:</strong> {{ $generation->assessment_type->label() }}</p>
        <p><strong>Tingkat kesulitan:</strong> {{ $generation->difficulty_level->label() }}</p>
        <p><strong>Bentuk soal:</strong> {{ $generation->question_type->label() }}</p>
        <p><strong>Jumlah soal:</strong> {{ $generation->question_count }}</p>
        <p><strong>Bahasa keluaran:</strong> {{ $languageLabels[$generation->output_language?->value] ?? 'Bahasa tidak dikenali' }}</p>
        <p class="muted">Antrian {{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>

        @if (! $isTerminal)
            <p class="muted">Halaman akan dimuat ulang otomatis saat status generasi berubah. Jika JavaScript dimatikan, muat ulang halaman secara manual.</p>
            <noscript>
                <p>Muat ulang halaman untuk melihat status terbaru.</p>
            </noscript>
        @endif

        @if ($status->value === 'failed')
            <p>{{ $generation->error_message }}</p>
            @can('retry', $generation)
                <form method="POST" action="{{ route('generations.retry', $generation) }}">
                    @csrf
                    <x-ui.button type="submit">Coba lagi</x-ui.button>
                </form>
            @endcan
        @endif

        @error('generation')
            <div class="error-text">{{ $message }}</div>
        @enderror
        @error('quota')
            <div class="error-text">{{ $message }}</div>
        @enderror
        @error('material')
            <div class="error-text">{{ $message }}</div>
        @enderror
        @error('question_type')
            <div class="error-text">{{ $message }}</div>
        @enderror
        @error('result')
            <div class="error-text">{{ $message }}</div>
        @enderror

        @if ($status->value === 'completed')
            @if ($generation->questionSet)
                <p>
                    <x-ui.button href="{{ route('question-sets.show', $generation->questionSet) }}">Buka bank soal</x-ui.button>
                </p>
            @else
                <form method="POST" action="{{ route('question-sets.import', $generation) }}">
                    @csrf
                    <x-ui.button type="submit">Simpan ke bank soal</x-ui.button>
                </form>
            @endif
        @endif
    </x-ui.panel>

    @if ($status->value === 'completed' && count($questions) > 0)
        <x-ui.panel>
            <h2>Soal</h2>
            @foreach ($questions as $index => $question)
                <article>
                    <p>
                        <strong>{{ $index + 1 }}.</strong>
                        {{ $question['question'] ?? '' }}
                    </p>
                    <p><strong>A.</strong> {{ $question['options']['A'] ?? '' }}</p>
                    <p><strong>B.</strong> {{ $question['options']['B'] ?? '' }}</p>
                    <p><strong>C.</strong> {{ $question['options']['C'] ?? '' }}</p>
                    <p><strong>D.</strong> {{ $question['options']['D'] ?? '' }}</p>
                    <p><strong>Jawaban benar:</strong> {{ $question['correct_answer'] ?? '' }}</p>
                    <p><strong>Penjelasan:</strong> {{ $question['explanation'] ?? '' }}</p>
                </article>
            @endforeach
        </x-ui.panel>
    @endif
@endsection

@if (! $isTerminal)
    @push('scripts')
        <script>
            (function () {
                var statusUrl = @json(route('generations.status', $generation));
                var initialStatus = @json($generation->generation_status->value);
                var live = document.getElementById('generation-status-live');
                var labels = {
                    queued: 'Menunggu diproses',
                    processing: 'Sedang diproses',
                    completed: 'Selesai',
                    failed: 'Gagal',
                    cancelled: 'Dibatalkan'
                };

                function poll() {
                    fetch(statusUrl, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    }).then(function (response) {
                        if (! response.ok) {
                            window.setTimeout(poll, 5000);
                            return null;
                        }
                        return response.json();
                    }).then(function (data) {
                        if (! data) {
                            return;
                        }
                        if (live && data.generation_status) {
                            live.textContent = 'Status saat ini: ' + (labels[data.generation_status] || 'Status tidak dikenali');
                        }
                        if (data.generation_status !== initialStatus || data.terminal) {
                            window.location.reload();
                            return;
                        }
                        window.setTimeout(poll, 5000);
                    }).catch(function () {
                        window.setTimeout(poll, 5000);
                    });
                }

                window.setTimeout(poll, 5000);
            })();
        </script>
    @endpush
@endif
