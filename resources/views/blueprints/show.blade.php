@php
    $isAdvanced = $blueprint->mode->value === 'advanced';
    $canMutateAdvanced = ! $isAdvanced || $isPro;
    $canEditDraft = $blueprint->lifecycle_status->value === 'draft'
        && ! $blueprint->ai_fill_status->isInFlight()
        && $canMutateAdvanced;
@endphp

@extends('layouts.app')

@section('title', $blueprint->title)

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.blueprints.index', $material) }}">Kembali ke kisi-kisi</a>
    </div>

    <p class="muted">KISI-KISI</p>
    <h1>{{ $blueprint->title }}</h1>
    <p>
        <span class="status">{{ $blueprint->lifecycle_status->value }}</span>
        <span class="muted">sumber {{ $blueprint->source->value }}</span>
        <span class="muted">mode {{ $blueprint->mode->label() }}{{ $isAdvanced ? ' (Pro)' : '' }}</span>
        @if ($blueprint->ai_fill_status->isInFlight())
            <span class="status status-warn">AI {{ $blueprint->ai_fill_status->value }}</span>
        @endif
    </p>

    @if ($blueprint->error_message)
        <div class="alert alert-error">{{ $blueprint->error_message }}</div>
    @endif

    @if ($isAdvanced && ! $isPro)
        <div class="alert alert-error">Paket Pro tidak aktif. Kisi-kisi lanjutan tetap dapat dilihat, tetapi tidak dapat diubah, dikonfirmasi, disalin, atau dipakai untuk generasi baru.</div>
    @endif

    <div class="actions" style="margin-bottom: 20px;">
        @if ($canEditDraft)
            <form method="POST" action="{{ route('materials.blueprints.confirm', [$material, $blueprint]) }}">
                @csrf
                <button class="button" type="submit">Konfirmasi kisi-kisi</button>
            </form>
        @endif

        @if ($blueprint->lifecycle_status->value === 'confirmed')
            <a class="button" href="{{ route('materials.blueprints.download', [$material, $blueprint]) }}">Unduh DOCX</a>
            @if ($canMutateAdvanced)
                <a class="button" href="{{ route('generation-runs.create', [$material, $blueprint]) }}">Generate soal</a>
                <form method="POST" action="{{ route('materials.blueprints.clone', [$material, $blueprint]) }}">
                    @csrf
                    <button class="button button-secondary" type="submit">Salin ke draf baru</button>
                </form>
            @endif
        @endif

        @if ($blueprint->ai_fill_status->value === 'failed' && $canMutateAdvanced)
            <form method="POST" action="{{ route('materials.blueprints.retry-ai', [$material, $blueprint]) }}">
                @csrf
                <button class="button button-secondary" type="submit">Coba isi AI lagi</button>
            </form>
        @endif
    </div>

    @if ($canEditDraft)
        <div class="card" style="margin-bottom: 20px;">
            <form method="POST" action="{{ route('materials.blueprints.update', [$material, $blueprint]) }}">
                @csrf
                @method('PATCH')
                @include('blueprints._form', [
                    'blueprint' => $blueprint,
                    'assessments' => $assessments,
                    'cognitiveLevels' => $cognitiveLevels,
                    'difficulties' => $difficulties,
                    'maxRows' => $maxRows ?? 5,
                    'mappingOptions' => $mappingOptions,
                    'isPro' => $isPro,
                    'questionTypes' => $questionTypes ?? \App\Enums\QuestionType::cases(),
                ])
                <button class="button" type="submit">Simpan draf</button>
            </form>
        </div>
    @else
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tujuan</th>
                        <th>Topik</th>
                        <th>Indikator</th>
                        <th>Level</th>
                        <th>Kesulitan</th>
                        <th>Tipe</th>
                        <th>Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blueprint->rows as $row)
                        <tr>
                            <td>{{ $row->objective }}</td>
                            <td>{{ $row->topic }}</td>
                            <td>{{ $row->indicator }}</td>
                            <td>{{ $row->cognitive_level->label() }}</td>
                            <td>{{ $row->difficulty->value }}</td>
                            <td>{{ $row->question_type->label() }}</td>
                            <td>{{ $row->requested_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($blueprint->ai_fill_status->isInFlight())
        <script>
            (function () {
                const interval = {{ (int) $pollIntervalMs }};
                const url = @json(route('materials.blueprints.status', [$material, $blueprint]));

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
