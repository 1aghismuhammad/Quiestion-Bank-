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

    <p>Total soal: {{ $run->total_requested_questions }} · Kredit: {{ $run->credits_required }}</p>
    <p class="muted">{{ $presentation->questionOrderLabel }} · {{ $presentation->optionOrderLabel }}</p>

    @if ($run->error_message)
        <x-ui.alert variant="danger">{{ $run->error_message }}</x-ui.alert>
    @endif

    <x-ui.panel>
        <h2>Langkah</h2>
        <div class="responsive-table table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Status</th>
                        <th>Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($run->children as $child)
                        @php
                            $childStatus = $child->generation_status->value;
                        @endphp
                        <tr>
                            <td>{{ $child->child_index }}</td>
                            <td>
                                <x-ui.status-badge :variant="$statusVariants[$childStatus] ?? 'neutral'">
                                    {{ $statusLabels[$childStatus] ?? 'Status tidak dikenali' }}
                                </x-ui.status-badge>
                            </td>
                            <td>{{ $child->question_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="responsive-summary">
            @foreach ($run->children as $child)
                @php
                    $childStatus = $child->generation_status->value;
                @endphp
                <article class="summary-row">
                    <strong>Langkah {{ $child->child_index }}</strong>
                    <x-ui.status-badge :variant="$statusVariants[$childStatus] ?? 'neutral'">
                        {{ $statusLabels[$childStatus] ?? 'Status tidak dikenali' }}
                    </x-ui.status-badge>
                    <p class="muted">{{ $child->question_count }} soal</p>
                </article>
            @endforeach
        </div>
    </x-ui.panel>

    @if ($run->status->value === 'completed')
        @error('generation_run')
            <div class="error-text">{{ $message }}</div>
        @enderror
        @error('result')
            <div class="error-text">{{ $message }}</div>
        @enderror

        @if ($run->questionSet)
            <p style="margin-bottom: 20px;">
                <x-ui.button href="{{ route('question-sets.show', $run->questionSet) }}">Buka bank soal</x-ui.button>
            </p>
        @else
            <form method="POST" action="{{ route('question-sets.import-run', $run) }}" style="margin-bottom: 20px;">
                @csrf
                <x-ui.button type="submit">Simpan ke bank soal</x-ui.button>
            </form>
        @endif

        <x-ui.panel>
            <h2>Soal yang dihasilkan</h2>
            @foreach ($presentation->questions as $question)
                <article style="margin-bottom: 16px;">
                    <p><strong>{{ $question->number }}. {{ $question->question }}</strong></p>
                    @if ($question->questionType->value === 'essay')
                        @if ($question->modelAnswer)
                            <p><strong>Contoh jawaban</strong></p>
                            <p>{{ $question->modelAnswer }}</p>
                        @endif
                        @if ($question->rubric)
                            <p><strong>Rubrik</strong></p>
                            <p>{{ $question->rubric }}</p>
                        @endif
                    @elseif ($question->options !== [])
                        <ul>
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
                        <p class="muted">Kunci: {{ $question->correctAnswer }}</p>
                    @endif
                    @if ($question->explanation !== '')
                        <p><strong>Pembahasan</strong></p>
                        <p>{{ $question->explanation }}</p>
                    @endif
                </article>
            @endforeach
        </x-ui.panel>
    @endif

    @if ($run->status->value === 'failed' && $canRetry)
        <form method="POST" action="{{ route('generation-runs.retry', $run) }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
            <x-ui.button type="submit">Coba lagi</x-ui.button>
        </form>
    @elseif ($run->status->value === 'failed' && ! $canRetry)
        <x-ui.alert variant="danger">Paket Pro aktif diperlukan untuk mencoba ulang generasi lanjutan.</x-ui.alert>
    @endif

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
