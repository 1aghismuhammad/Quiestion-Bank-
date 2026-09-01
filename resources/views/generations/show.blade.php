@php
    $status = $generation->generation_status;
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
    $statusClass = match ($status->value) {
        'queued', 'processing' => 'status status-warn',
        'failed', 'cancelled' => 'status status-error',
        default => 'status',
    };
@endphp

@extends('layouts.app')

@section('title', 'Generasi soal')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('generations.index') }}">Riwayat generasi</a>
        @if ($generation->material)
            <a href="{{ route('materials.show', $generation->material) }}">Kembali ke materi</a>
        @endif
    </div>

    <p class="muted">GENERATION</p>
    <h1>Status generasi</h1>

    @include('generations._quota', ['usage' => $usage])

    <div class="card" style="margin-bottom: 20px;">
        <p>
            <strong>Status:</strong>
            <span class="{{ $statusClass }}" id="generation-status-label">{{ $statusLabels[$status->value] ?? $status->value }}</span>
            <span class="muted">({{ $status->value }})</span>
        </p>
        <p aria-live="polite" id="generation-status-live">
            Status saat ini: {{ $status->value }}
        </p>
        <p><strong>Materi:</strong> {{ $generation->material?->title ?? 'Materi tidak tersedia' }}</p>
        <p><strong>Tipe assessment:</strong> {{ $generation->assessment_type->value }}</p>
        <p><strong>Tingkat kesulitan:</strong> {{ $generation->difficulty_level->value }}</p>
        <p><strong>Tipe soal:</strong> {{ $generation->question_type->value }}</p>
        <p><strong>Jumlah soal:</strong> {{ $generation->question_count }}</p>
        <p><strong>Bahasa keluaran:</strong> {{ $languageLabels[$generation->output_language?->value] ?? $generation->output_language?->value }}</p>
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
                    <button class="button" type="submit">Coba lagi</button>
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
                    <a class="button" href="{{ route('question-sets.show', $generation->questionSet) }}">Lihat di Question Bank</a>
                </p>
            @else
                <form method="POST" action="{{ route('question-sets.import', $generation) }}">
                    @csrf
                    <button class="button" type="submit">Simpan ke Question Bank</button>
                </form>
            @endif
        @endif
    </div>

    @if ($status->value === 'completed' && count($questions) > 0)
        <div class="card">
            <h2>Pratinjau soal</h2>
            @foreach ($questions as $index => $question)
                <div style="margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #dce3ee;">
                    <p><strong>{{ $index + 1 }}.</strong> {{ $question['question'] ?? '' }}</p>
                    <p><strong>A.</strong> {{ $question['options']['A'] ?? '' }}</p>
                    <p><strong>B.</strong> {{ $question['options']['B'] ?? '' }}</p>
                    <p><strong>C.</strong> {{ $question['options']['C'] ?? '' }}</p>
                    <p><strong>D.</strong> {{ $question['options']['D'] ?? '' }}</p>
                    <p><strong>Jawaban benar:</strong> {{ $question['correct_answer'] ?? '' }}</p>
                    <p><strong>Penjelasan:</strong> {{ $question['explanation'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    @endif
@endsection

@if (! $isTerminal)
    @push('scripts')
        <script>
            (function () {
                var statusUrl = @json(route('generations.status', $generation));
                var initialStatus = @json($generation->generation_status->value);
                var live = document.getElementById('generation-status-live');

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
                            live.textContent = 'Status saat ini: ' + data.generation_status;
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
