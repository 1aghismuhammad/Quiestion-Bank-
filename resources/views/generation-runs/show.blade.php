@extends('layouts.app')

@section('title', 'Status generasi kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.blueprints.show', [$run->material, $run->blueprint]) }}">Kembali ke kisi-kisi</a>
    </div>

    <p class="muted">GENERATION RUN</p>
    <h1>{{ $run->blueprint?->title ?? 'Generasi kisi-kisi' }}</h1>
    <p>
        <span class="status {{ $run->status->value === 'failed' ? 'status-error' : ($run->status->value === 'completed' ? '' : 'status-warn') }}">
            {{ $run->status->value }}
        </span>
        <span class="muted">mode {{ $run->mode->label() }}</span>
    </p>
    <p>Total soal: {{ $run->total_requested_questions }} · Kredit: {{ $run->credits_required }}</p>
    <p class="muted">{{ $presentation->questionOrderLabel }} · {{ $presentation->optionOrderLabel }}</p>

    @if ($run->error_message)
        <div class="alert alert-error">{{ $run->error_message }}</div>
    @endif

    <div class="card" style="margin-bottom: 20px;">
        <h2>Langkah</h2>
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
                    <tr>
                        <td>{{ $child->child_index }}</td>
                        <td>{{ $child->generation_status->value }}</td>
                        <td>{{ $child->question_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($run->status->value === 'completed')
        <div class="card" style="margin-bottom: 20px;">
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
        </div>
    @endif

    @if ($run->status->value === 'failed' && $canRetry)
        <form method="POST" action="{{ route('generation-runs.retry', $run) }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
            <button class="button" type="submit">Coba lagi</button>
        </form>
    @elseif ($run->status->value === 'failed' && ! $canRetry)
        <div class="alert alert-error">Paket Pro aktif diperlukan untuk mencoba ulang generasi lanjutan.</div>
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
