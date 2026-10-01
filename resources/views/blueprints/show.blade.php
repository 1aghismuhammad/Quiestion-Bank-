@php
    $isAdvanced = $blueprint->mode->value === 'advanced';
    $canMutateAdvanced = ! $isAdvanced || $isPro;
    $canEditDraft = $blueprint->lifecycle_status->value === 'draft'
        && ! $blueprint->ai_fill_status->isInFlight()
        && $canMutateAdvanced;
    $isConfirmed = $blueprint->lifecycle_status->value === 'confirmed';
    $canRetryAi = $blueprint->lifecycle_status->value === 'draft'
        && $blueprint->ai_fill_status->value === 'failed'
        && $canMutateAdvanced;
    $hasActions = $canEditDraft || $isConfirmed || $canRetryAi;
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
        'queued' => 'Menunggu diproses',
        'processing' => 'Sedang diproses',
        default => null,
    };
    $totalQuestions = (int) $blueprint->rows->sum('requested_count');
    $estimatedCredits = \App\Support\Generations\GenerationCredits::required($totalQuestions);
@endphp

@extends('layouts.app')

@section('title', $blueprint->title)

@section('content')
    <div class="blueprint-detail-page">
        <div class="blueprint-detail-back">
            <a href="{{ route('materials.blueprints.index', $material) }}">
                <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                <span>Kembali ke kisi-kisi</span>
            </a>
        </div>

        <header class="blueprint-detail-hero">
            <div class="blueprint-detail-heading">
                <h1>{{ $blueprint->title }}</h1>
                <div class="blueprint-detail-status">
                    <x-ui.status-badge :variant="$lifecycleVariant">{{ $lifecycleLabel }}</x-ui.status-badge>
                    @if ($blueprint->ai_fill_status->isInFlight() && $aiFillLabel)
                        <x-ui.status-badge variant="processing">AI: {{ $aiFillLabel }}</x-ui.status-badge>
                    @endif
                    @if ($blueprint->lifecycle_status->value === 'draft' && $blueprint->ai_fill_status->value === 'failed')
                        <x-ui.status-badge variant="danger">Gagal</x-ui.status-badge>
                    @endif
                </div>
                <p class="muted">
                    Sumber {{ $sourceLabel }} · Mode {{ $blueprint->mode->label() }}{{ $isAdvanced ? ' (Pro)' : '' }}
                </p>
            </div>

            @if ($hasActions)
                <div class="blueprint-detail-actions">
                    @if ($canEditDraft)
                        <x-ui.button variant="secondary" type="submit" form="blueprint-draft-form">Simpan draf</x-ui.button>
                    @endif

                    @if ($canRetryAi)
                        <form method="POST" action="{{ route('materials.blueprints.retry-ai', [$material, $blueprint]) }}">
                            @csrf
                            <x-ui.button variant="secondary" type="submit">Coba isi AI lagi</x-ui.button>
                        </form>
                    @endif

                    @if ($isConfirmed)
                        <x-ui.button variant="secondary" href="{{ route('materials.blueprints.download', [$material, $blueprint]) }}">Unduh DOCX</x-ui.button>
                        @if ($canMutateAdvanced)
                            <form method="POST" action="{{ route('materials.blueprints.clone', [$material, $blueprint]) }}">
                                @csrf
                                <x-ui.button variant="secondary" type="submit">Salin ke draf baru</x-ui.button>
                            </form>
                        @endif
                    @endif

                    @if ($canEditDraft)
                        <form method="POST" action="{{ route('materials.blueprints.confirm', [$material, $blueprint]) }}">
                            @csrf
                            <x-ui.button type="submit">Konfirmasi kisi-kisi</x-ui.button>
                        </form>
                    @endif

                    @if ($isConfirmed && $canMutateAdvanced)
                        <x-ui.button href="{{ route('generation-runs.create', [$material, $blueprint]) }}">Buat soal</x-ui.button>
                    @endif
                </div>
            @endif
        </header>

        @if ($blueprint->ai_fill_status->isInFlight())
            <section class="blueprint-detail-processing" role="status">
                <svg class="blueprint-detail-processing-icon" aria-hidden="true" width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h5M20 20v-5h-5M5.6 15a8 8 0 0013.9 2M18.4 9A8 8 0 004.5 7"></path></svg>
                <div>
                    <p class="blueprint-detail-processing-title">{{ $aiFillLabel }}</p>
                    <p class="muted">Pengisian kisi-kisi oleh AI sedang berjalan. Halaman akan diperbarui saat selesai.</p>
                </div>
            </section>
        @endif

        @if ($blueprint->error_message)
            <x-ui.alert variant="danger">{{ $blueprint->error_message }}</x-ui.alert>
        @endif

        @if ($blueprint->ai_fill_status->value === 'succeeded' && $blueprint->lifecycle_status->value === 'draft')
            <x-ui.alert variant="info">Pengisian AI selesai. Kisi-kisi ini masih draf sampai dikonfirmasi.</x-ui.alert>
        @endif

        @if ($isAdvanced && ! $isPro)
            <x-ui.alert variant="danger">Paket Pro tidak aktif. Kisi-kisi lanjutan tetap dapat dilihat, tetapi tidak dapat diubah, dikonfirmasi, disalin, atau dipakai untuk generasi baru.</x-ui.alert>
        @endif

        @if ($canEditDraft)
            <form id="blueprint-draft-form" class="blueprint-detail-form" method="POST" action="{{ route('materials.blueprints.update', [$material, $blueprint]) }}">
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
                    'detailLayout' => true,
                ])
            </form>
        @else
            <section class="blueprint-live-summary blueprint-detail-static-summary" aria-labelledby="blueprint-summary-title">
                <h2 id="blueprint-summary-title">Ringkasan kisi-kisi</h2>
                <dl class="blueprint-summary-list">
                    <div><dt>Total soal</dt><dd>{{ $totalQuestions }}</dd></div>
                    <div><dt>Perkiraan kredit</dt><dd>{{ $estimatedCredits }}</dd></div>
                </dl>
            </section>

            <div class="blueprint-detail-confirmed">
                <h2 class="blueprint-form-rows-title">Baris kisi-kisi</h2>
                <div class="responsive-table table-wrap blueprint-detail-table-wrap">
                    <table class="table blueprint-detail-table">
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
                <div class="responsive-summary blueprint-detail-mobile-rows">
                    @php $summaryCursor = 1; @endphp
                    @foreach ($blueprint->rows as $row)
                        @php
                            $start = $summaryCursor;
                            $end = $start + $row->requested_count - 1;
                            $questionRange = $start === $end ? (string) $start : "{$start}–{$end}";
                            $summaryCursor = $end + 1;
                        @endphp
                        <article class="blueprint-detail-mobile-row">
                            <h3>Baris {{ $loop->iteration }}</h3>
                            <dl>
                                <div><dt>Kompetensi / Tujuan Pembelajaran</dt><dd>{{ $row->objective }}</dd></div>
                                <div><dt>Materi</dt><dd>{{ $row->topic }}</dd></div>
                                <div><dt>Indikator Soal</dt><dd>{{ $row->indicator }}</dd></div>
                                <div><dt>Level Kognitif</dt><dd>{{ $row->cognitive_level->label() }}</dd></div>
                                <div><dt>Bentuk Soal</dt><dd>{{ $row->question_type->label() }}</dd></div>
                                <div><dt>No. Soal</dt><dd>{{ $questionRange }}</dd></div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

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
