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
    $runStatus = $run->status->value;
    $runLabel = $statusLabels[$runStatus] ?? 'Status tidak dikenali';
    $runVariant = $statusVariants[$runStatus] ?? 'neutral';
@endphp

@extends('layouts.app')

@section('title', 'Status generasi kisi-kisi')

@section('content')
    <div class="generation-run-show-page">
        <x-ui.page-header>
            {{ $run->blueprint?->title ?? 'Generasi kisi-kisi' }}
            <x-slot:back>
                <x-ui.button variant="tertiary" href="{{ route('materials.blueprints.show', [$run->material, $run->blueprint]) }}">Kembali ke kisi-kisi</x-ui.button>
            </x-slot:back>
            <x-slot:supporting>Mode {{ $run->mode->label() }}</x-slot:supporting>
            <x-slot:status>
                <x-ui.status-badge :variant="$runVariant" data-run-status="{{ $runStatus }}">{{ $runLabel }}</x-ui.status-badge>
            </x-slot:status>
        </x-ui.page-header>

        <section class="generation-run-show-summary" aria-label="Ringkasan generasi">
            <p><span class="muted">Total soal</span> <strong>{{ $run->total_requested_questions }}</strong></p>
            <p><span class="muted">Kredit</span> <strong>{{ $run->credits_required }}</strong></p>
            <p>{{ $presentation->questionOrderLabel }}</p>
            <p>{{ $presentation->optionOrderLabel }}</p>
        </section>

        <x-ui.panel class="generation-run-show-steps">
            <h2>Langkah</h2>
            @if (! $run->status->isTerminal())
                <p class="muted">Proses generasi sedang berjalan.</p>
            @endif
            <ol class="generation-run-show-step-list">
                @foreach ($run->children as $child)
                    @php
                        $childStatus = $child->generation_status->value;
                    @endphp
                    <li class="generation-run-show-step">
                        <strong>Langkah {{ $child->child_index }}</strong>
                        <x-ui.status-badge :variant="$statusVariants[$childStatus] ?? 'neutral'">
                            {{ $statusLabels[$childStatus] ?? 'Status tidak dikenali' }}
                        </x-ui.status-badge>
                        <span class="muted">{{ $child->question_count }} soal</span>
                    </li>
                @endforeach
            </ol>
        </x-ui.panel>

        @if ($run->error_message)
            <x-ui.alert variant="danger">{{ $run->error_message }}</x-ui.alert>
        @endif

        @if ($run->status->value === 'failed' && $canRetry)
            <form class="generation-run-show-action" method="POST" action="{{ route('generation-runs.retry', $run) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
                <x-ui.button type="submit">Coba lagi</x-ui.button>
            </form>
        @elseif ($run->status->value === 'failed' && ! $canRetry)
            <x-ui.alert variant="danger">Paket Pro aktif diperlukan untuk mencoba ulang generasi lanjutan.</x-ui.alert>
        @endif

        @if ($run->status->value === 'completed')
            @error('generation_run')
                <div class="error-text">{{ $message }}</div>
            @enderror
            @error('result')
                <div class="error-text">{{ $message }}</div>
            @enderror

            @if ($run->questionSet)
                <p class="generation-run-show-action">
                    <x-ui.button href="{{ route('question-sets.show', $run->questionSet) }}">Buka bank soal</x-ui.button>
                </p>
            @else
                <form class="generation-run-show-action" method="POST" action="{{ route('question-sets.import-run', $run) }}">
                    @csrf
                    <x-ui.button type="submit">Simpan ke bank soal</x-ui.button>
                </form>
            @endif

            <section class="generation-run-show-results" aria-labelledby="generation-run-results-title">
                <h2 id="generation-run-results-title">Soal yang dihasilkan</h2>
                @foreach ($presentation->questions as $question)
                    <article class="generation-run-show-question">
                        <p class="generation-run-show-stem"><strong>{{ $question->number }}. {{ $question->question }}</strong></p>
                        @if ($question->questionType->value === 'essay')
                            @if ($question->modelAnswer)
                                <div class="generation-run-show-block">
                                    <p><strong>Contoh jawaban</strong></p>
                                    <p>{{ $question->modelAnswer }}</p>
                                </div>
                            @endif
                            @if ($question->rubric)
                                <div class="generation-run-show-block">
                                    <p><strong>Rubrik</strong></p>
                                    <p>{{ $question->rubric }}</p>
                                </div>
                            @endif
                        @elseif ($question->options !== [])
                            <ul class="generation-run-show-options">
                                @foreach ($question->options as $label => $option)
                                    @if ($question->questionType->value === 'true_false')
                                        <li>{{ $label }}</li>
                                    @else
                                        <li>{{ $label }}. {{ $option }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                        @if ($question->correctAnswer !== '')
                            <p class="generation-run-show-key">Kunci: {{ $question->correctAnswer }}</p>
                        @endif
                        @if ($question->explanation !== '')
                            <div class="generation-run-show-block">
                                <p><strong>Pembahasan</strong></p>
                                <p>{{ $question->explanation }}</p>
                            </div>
                        @endif
                    </article>
                @endforeach
            </section>
        @endif
    </div>

    @if (! $run->status->isTerminal())
        <script>
            (function () {
                const interval = {{ (int) $pollIntervalMs }};
                const url = @json(route('generation-runs.status', $run));

                async function poll() {
                    try {
                        const response = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                        const payload = await response.json();
                        if (payload.terminal) {
                            window.location.reload();
                        }
                    } catch (e) {}
                }

                setInterval(poll, interval);
            })();
        </script>
    @endif
@endsection
