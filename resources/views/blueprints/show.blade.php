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
        @if ($blueprint->ai_fill_status->isInFlight())
            <span class="status status-warn">AI {{ $blueprint->ai_fill_status->value }}</span>
        @endif
    </p>

    @if ($blueprint->error_message)
        <div class="alert alert-error">{{ $blueprint->error_message }}</div>
    @endif

    <div class="actions" style="margin-bottom: 20px;">
        @if ($blueprint->lifecycle_status->value === 'draft' && ! $blueprint->ai_fill_status->isInFlight())
            <form method="POST" action="{{ route('materials.blueprints.confirm', [$material, $blueprint]) }}">
                @csrf
                <button class="button" type="submit">Konfirmasi kisi-kisi</button>
            </form>
        @endif

        @if ($blueprint->lifecycle_status->value === 'confirmed')
            <a class="button" href="{{ route('materials.blueprints.download', [$material, $blueprint]) }}">Unduh DOCX</a>
            <a class="button" href="{{ route('generation-runs.create', [$material, $blueprint]) }}">Generate soal</a>
            <form method="POST" action="{{ route('materials.blueprints.clone', [$material, $blueprint]) }}">
                @csrf
                <button class="button button-secondary" type="submit">Salin ke draf baru</button>
            </form>
        @endif

        @if ($blueprint->ai_fill_status->value === 'failed')
            <form method="POST" action="{{ route('materials.blueprints.retry-ai', [$material, $blueprint]) }}">
                @csrf
                <button class="button button-secondary" type="submit">Coba isi AI lagi</button>
            </form>
        @endif
    </div>

    @if ($blueprint->lifecycle_status->value === 'draft' && ! $blueprint->ai_fill_status->isInFlight())
        <div class="card" style="margin-bottom: 20px;">
            <form method="POST" action="{{ route('materials.blueprints.update', [$material, $blueprint]) }}">
                @csrf
                @method('PATCH')
                @include('blueprints._form', [
                    'blueprint' => $blueprint,
                    'assessments' => $assessments,
                    'cognitiveLevels' => $cognitiveLevels,
                    'difficulties' => $difficulties,
                    'maxRows' => 5,
                    'mappingOptions' => $mappingOptions,
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
