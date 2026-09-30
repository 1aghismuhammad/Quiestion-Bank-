@php
    $isAdvanced = $blueprint->mode->value === 'advanced';
    $canMutateAdvanced = ! $isAdvanced || $isPro;
    $canEditDraft = $blueprint->lifecycle_status->value === 'draft'
        && ! $blueprint->ai_fill_status->isInFlight()
        && $canMutateAdvanced;
    $lifecycleLabel = match ($blueprint->lifecycle_status->value) {
        'draft' => 'Draf',
        'confirmed' => 'Dikonfirmasi',
        default => 'Status tidak dikenali',
    };
    $lifecycleVariant = match ($blueprint->lifecycle_status->value) {
        'draft' => 'neutral',
        'confirmed' => 'success',
        default => 'neutral',
    };
    $sourceLabel = match ($blueprint->source->value) {
        'manual' => 'Manual',
        'ai' => 'AI',
        default => 'Sumber tidak dikenali',
    };
    $aiFillLabel = match ($blueprint->ai_fill_status->value) {
        'queued' => 'Dalam antrean',
        'processing' => 'Sedang diproses',
        default => null,
    };
@endphp

@extends('layouts.app')

@section('title', $blueprint->title)

@section('content')
    <x-ui.page-header>
        {{ $blueprint->title }}
        <x-slot:back>
            <x-ui.button variant="tertiary" href="{{ route('materials.blueprints.index', $material) }}">Kembali ke kisi-kisi</x-ui.button>
        </x-slot:back>
        <x-slot:supporting>
            Sumber {{ $sourceLabel }} · Mode {{ $blueprint->mode->label() }}{{ $isAdvanced ? ' (Pro)' : '' }}
        </x-slot:supporting>
        <x-slot:status>
            <x-ui.status-badge :variant="$lifecycleVariant">{{ $lifecycleLabel }}</x-ui.status-badge>
            @if ($blueprint->ai_fill_status->isInFlight() && $aiFillLabel)
                <x-ui.status-badge variant="processing">AI {{ $aiFillLabel }}</x-ui.status-badge>
            @endif
            @if ($blueprint->lifecycle_status->value === 'draft' && $blueprint->ai_fill_status->value === 'failed')
                <x-ui.status-badge variant="danger">Gagal</x-ui.status-badge>
            @endif
        </x-slot:status>
    </x-ui.page-header>

    @if ($blueprint->error_message)
        <x-ui.alert variant="danger">{{ $blueprint->error_message }}</x-ui.alert>
    @endif

    @if ($blueprint->ai_fill_status->value === 'succeeded' && $blueprint->lifecycle_status->value === 'draft')
        <x-ui.alert variant="info">Pengisian AI selesai. Kisi-kisi ini masih draf sampai dikonfirmasi.</x-ui.alert>
    @endif

    @if ($isAdvanced && ! $isPro)
        <x-ui.alert variant="danger">Paket Pro tidak aktif. Kisi-kisi lanjutan tetap dapat dilihat, tetapi tidak dapat diubah, dikonfirmasi, disalin, atau dipakai untuk generasi baru.</x-ui.alert>
    @endif

    <div class="action-stack" style="margin-bottom: 20px;">
        @if ($canEditDraft)
            <form method="POST" action="{{ route('materials.blueprints.confirm', [$material, $blueprint]) }}">
                @csrf
                <x-ui.button type="submit">Konfirmasi kisi-kisi</x-ui.button>
            </form>
        @endif

        @if ($blueprint->lifecycle_status->value === 'confirmed')
            @if ($canMutateAdvanced)
                <x-ui.button href="{{ route('generation-runs.create', [$material, $blueprint]) }}">Buat soal</x-ui.button>
            @endif
            <x-ui.button variant="secondary" href="{{ route('materials.blueprints.download', [$material, $blueprint]) }}">Unduh DOCX</x-ui.button>
            @if ($canMutateAdvanced)
                <form method="POST" action="{{ route('materials.blueprints.clone', [$material, $blueprint]) }}">
                    @csrf
                    <x-ui.button variant="secondary" type="submit">Salin ke draf baru</x-ui.button>
                </form>
            @endif
        @endif

        @if ($blueprint->lifecycle_status->value === 'draft' && $blueprint->ai_fill_status->value === 'failed' && $canMutateAdvanced)
            <form method="POST" action="{{ route('materials.blueprints.retry-ai', [$material, $blueprint]) }}">
                @csrf
                <x-ui.button variant="secondary" type="submit">Coba isi AI lagi</x-ui.button>
            </form>
        @endif
    </div>

    @if ($canEditDraft)
        <div class="page-form">
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
                    'importLinked' => $blueprint->sourceImport()->exists(),
                    'isPro' => $isPro,
                    'questionTypes' => $questionTypes ?? \App\Enums\QuestionType::cases(),
                ])
                <x-ui.button variant="secondary" type="submit">Simpan draf</x-ui.button>
            </form>
        </div>
    @else
        <div class="responsive-table table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kompetensi / Tujuan Pembelajaran</th>
                        <th>Materi</th>
                        <th>Indikator Soal</th>
                        <th>Level Kognitif</th>
                        <th>Bentuk Soal</th>
                        <th>No. Soal</th>
                    </tr>
                </thead>
                <tbody>
                    @php $questionCursor = 1; @endphp
                    @foreach ($blueprint->rows as $row)
                        @php
                            $start = $questionCursor;
                            $end = $start + $row->requested_count - 1;
                            $questionRange = $start === $end ? (string) $start : "{$start}–{$end}";
                            $questionCursor = $end + 1;
                        @endphp
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $row->objective }}</td>
                            <td>{{ $row->topic }}</td>
                            <td>{{ $row->indicator }}</td>
                            <td>{{ $row->cognitive_level->label() }}</td>
                            <td>{{ $row->question_type->label() }}</td>
                            <td>{{ $questionRange }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="responsive-summary">
            @php $summaryCursor = 1; @endphp
            @foreach ($blueprint->rows as $row)
                @php
                    $start = $summaryCursor;
                    $end = $start + $row->requested_count - 1;
                    $questionRange = $start === $end ? (string) $start : "{$start}–{$end}";
                    $summaryCursor = $end + 1;
                @endphp
                <article class="summary-row">
                    <strong>Baris {{ $loop->iteration }}</strong>
                    <p>{{ $row->objective }}</p>
                    <p class="muted">{{ $row->topic }} · {{ $row->indicator }}</p>
                    <p class="muted">{{ $row->cognitive_level->label() }} · {{ $row->question_type->label() }} · No. {{ $questionRange }}</p>
                </article>
            @endforeach
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
