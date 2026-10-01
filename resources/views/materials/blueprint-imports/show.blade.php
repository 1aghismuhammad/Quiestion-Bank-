@extends('layouts.app')

@section('title', 'Tinjauan impor kisi-kisi')

@section('content')
        <div class="blueprint-import-review__page">
            <div class="blueprint-import-review__back">
                <a href="{{ route('materials.blueprint-imports.index', $material) }}">
                    <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Kembali ke riwayat impor
                </a>
            </div>

            <div class="blueprint-import-review__hero">
                <p class="muted">TINJAUAN IMPOR</p>
                <h1>{{ $review->originalFileName }}</h1>
            </div>

            @if ($import->created_blueprint_id)
                <div class="blueprint-import-review__created" style="margin-bottom: 24px;">
                    <h2>Draf kisi-kisi sudah dibuat.</h2>
                    <x-ui.button href="{{ route('materials.blueprints.show', [$material, $import->created_blueprint_id]) }}">Buka draf</x-ui.button>
                </div>
            @endif

            <div class="blueprint-import-review__progress" id="import-review-status">
        @php
            $presentationVariant = function (?string $status, string $pipeline): string {
                return match ($pipeline) {
                    'extraction' => match ($status) {
                        'pending' => 'neutral',
                        'processing' => 'processing',
                        'extracted' => 'success',
                        'failed' => 'danger',
                        default => 'neutral',
                    },
                    'interpretation' => match ($status) {
                        null, '' => 'neutral',
                        'queued' => 'neutral',
                        'processing' => 'processing',
                        'review_ready' => 'success',
                        'failed' => 'danger',
                        default => 'neutral',
                    },
                    'grounding' => match ($status) {
                        null, '' => 'neutral',
                        'queued' => 'neutral',
                        'processing' => 'processing',
                        'ready' => 'success',
                        'failed' => 'danger',
                        default => 'neutral',
                    },
                    default => 'neutral',
                };
            };
            $groundingText = trim(view('materials.blueprint-imports._grounding-label', [
                'status' => $import->grounding_status?->value,
            ])->render());
        @endphp
                <x-ui.import-progress
                    class="blueprint-import-review__stages"
                    :extraction-label="$review->extractionStatusLabel"
                    :extraction-variant="$presentationVariant($review->extractionStatus, 'extraction')"
                    :interpretation-label="$review->interpretationStatusLabel"
                    :interpretation-variant="$presentationVariant($review->interpretationStatus, 'interpretation')"
                    :grounding-label="$groundingText"
                    :grounding-variant="$presentationVariant($import->grounding_status?->value, 'grounding')"
                />

                @if ($review->isInFlight() || $groundingInFlight)
                    <div class="blueprint-import-review__async" role="status">
                        <svg class="blueprint-import-review__async-icon" aria-hidden="true" width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h5M20 20v-5h-5M5.6 15a8 8 0 0013.9 2M18.4 9A8 8 0 004.5 7"></path></svg>
                        <p class="muted" id="import-state-live">Proses masih berjalan. Halaman akan dimuat ulang saat selesai.</p>
                    </div>
                @endif

                @if ($review->interpretationStatus === 'failed')
                    <x-ui.alert variant="danger">
                        <p>Interpretasi gagal.</p>
                        @if ($review->errorMessage)
                            <p>{{ $review->errorMessage }}</p>
                        @endif
                    </x-ui.alert>
                @endif

                @if ($review->extractionStatus === 'failed')
                    <x-ui.alert variant="danger">
                        <p>Ekstraksi gagal. Interpretasi tidak dijalankan.</p>
                        @if ($import->error_message)
                            <p>{{ $import->error_message }}</p>
                        @endif
                    </x-ui.alert>
                @endif

                @if ($review->interpretationStatus === 'review_ready' && $import->grounding_status === null)
                    <form method="POST" action="{{ route('materials.blueprint-imports.ground', [$material, $import]) }}" class="blueprint-import-review__ground-form">
                        @csrf
                        <p class="muted">Kisi-kisi akan dibandingkan dengan materi agar tujuan, topik, dan indikator memiliki acuan yang sesuai.</p>
                        <x-ui.button type="submit">Cocokkan dengan materi</x-ui.button>
                    </form>
                @endif

                @if ($import->grounding_status?->value === 'failed')
                    <form method="POST" action="{{ route('materials.blueprint-imports.retry-grounding', [$material, $import]) }}" class="blueprint-import-review__retry">
                        @csrf
                        <x-ui.button type="submit">Coba lagi pencocokan</x-ui.button>
                    </form>
                @endif

                @if ($review->canRetry)
                    <form method="POST" action="{{ route('materials.blueprint-imports.retry', [$material, $import]) }}" class="blueprint-import-review__retry">
                        @csrf
                        <x-ui.button type="submit">Coba lagi interpretasi</x-ui.button>
                    </form>
                @endif
            </div>

            @if ($review->presentationError)
                <x-ui.alert variant="danger" class="blueprint-import-review__banner">{{ $review->presentationError }}</x-ui.alert>
            @elseif ($review->interpretationStatus === 'review_ready' && $review->resultValid)
                <div class="blueprint-import-review__main">
                    <div class="blueprint-import-review__candidates">
                        <div class="blueprint-import-review__candidates-header">
                            <div>
                                <h2>Kandidat hasil interpretasi</h2>
                                <p class="muted">Periksa hasil pembacaan kisi-kisi sebelum melanjutkan pembuatan draf.</p>
                            </div>
                            @if ($candidates->total() > 0)
                                <span class="blueprint-import-review__count">Total: {{ $candidates->total() }} kandidat ditemukan</span>
                            @endif
                        </div>

                        <div class="blueprint-import-review__meta">
                            <h3>Klasifikasi dokumen</h3>
                            <p>{{ $review->documentKindLabel }}</p>

                            @if ($review->topLevelWarnings !== [])
                                <h3 style="margin-top: 12px; color: var(--color-warning);">Peringatan</h3>
                                <ul style="margin: 4px 0 0; padding-left: 20px; color: var(--color-warning);">
                                    @foreach ($review->topLevelWarnings as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        @if ($candidates->total() === 0)
                            <div class="blueprint-import-review__zero">
                                <p class="muted">Tidak ada kandidat untuk ditinjau.</p>
                            </div>
                        @else
                            <p class="muted" style="margin-bottom: 12px; font-size: 0.875rem;">
                                Menampilkan {{ $candidates->firstItem() }}–{{ $candidates->lastItem() }}
                            </p>

                            @foreach ($candidates as $candidate)
                                @php
                                    $isEligible = in_array($candidate['index'], $convertibleIndexes, true);
                                    $hasReadyGrounding = $import->grounding_status?->value === 'ready';
                                    $cardState = $hasReadyGrounding
                                        ? ($isEligible ? 'eligible' : 'ineligible')
                                        : 'pending';
                                @endphp
                                <article class="blueprint-import-review__candidate blueprint-import-review__candidate--{{ $cardState }}">
                                    <header class="blueprint-import-review__candidate-header">
                                        <h3>Kandidat {{ $candidate['index'] + 1 }}</h3>
                                    </header>

                                    <div class="blueprint-import-review__candidate-fields">
                                        @foreach ($candidate['fields'] as $field)
                                            <div class="{{ $field['empty'] ? 'muted' : '' }} {{ in_array($field['label'], ['Kompetensi / Tujuan Pembelajaran', 'Indikator Soal']) ? 'blueprint-import-review__candidate-long' : 'blueprint-import-review__candidate-short' }}">
                                                <strong>{{ $field['label'] }}</strong>
                                                <span>{{ $field['value'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>

                                    @if ($candidate['unresolved'] !== [])
                                        <div class="blueprint-import-review__candidate-issues">
                                            <h4>Belum teridentifikasi</h4>
                                            <p class="muted">Belum berhasil diidentifikasi pada tahap interpretasi.</p>
                                            <ul>
                                                @foreach ($candidate['unresolved'] as $item)
                                                    <li>{{ $item['label'] }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($candidate['warnings'] !== [])
                                        <div class="blueprint-import-review__candidate-issues">
                                            <h4>Peringatan kandidat</h4>
                                            <ul>
                                                @foreach ($candidate['warnings'] as $warning)
                                                    <li>{{ $warning }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if ($candidate['provenance'] !== [])
                                        <footer class="blueprint-import-review__candidate-provenance muted">
                                            @foreach ($candidate['provenance'] as $group)
                                                <p>
                                                    <svg aria-hidden="true" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                    <strong>{{ $group['field_label'] }}:</strong>
                                                    {{ implode('; ', $group['locations']) }}
                                                </p>
                                            @endforeach
                                        </footer>
                                    @endif
                                </article>
                            @endforeach

                            @if ($candidates->hasPages())
                                <nav class="blueprint-import-review__pagination" aria-label="Halaman kandidat">
                                    @if ($candidates->onFirstPage())
                                        <span class="muted" aria-disabled="true">Sebelumnya</span>
                                    @else
                                        <a class="button button-secondary" href="{{ $candidates->previousPageUrl() }}">Sebelumnya</a>
                                    @endif

                                    @if ($candidates->hasMorePages())
                                        <a class="button button-secondary" href="{{ $candidates->nextPageUrl() }}">Berikutnya</a>
                                    @else
                                        <span class="muted" aria-disabled="true">Berikutnya</span>
                                    @endif
                                </nav>
                            @endif
                        @endif
                    </div>
                    <div class="blueprint-import-review__panel">
                        @if (! $import->created_blueprint_id && $import->grounding_status?->value === 'ready' && $convertibleIndexes !== [])
                            <form method="POST" action="{{ route('materials.blueprint-imports.convert', [$material, $import]) }}" class="blueprint-import-review__conversion">
                                @csrf
                                <header class="blueprint-import-review__conversion-header">
                                    <h2>Konfigurasi Draf Kisi-kisi</h2>
                                    <p class="muted">Pilih kandidat yang akan dipakai, lalu lengkapi isian kisi-kisi. Konteks materi ditentukan secara otomatis.</p>
                                </header>
                                @error('import')
                                    <div class="error-text">{{ $message }}</div>
                                @enderror
                                @error('selected_indexes')
                                    <div class="error-text">{{ $message }}</div>
                                @enderror

                                <div class="blueprint-import-review__conversion-global">
                                    <div class="blueprint-import-review__field">
                                        <label class="label" for="import-draft-title">Judul</label>
                                        <input id="import-draft-title" class="ui-input" name="title" value="{{ old('title') }}" required maxlength="120">
                                    </div>
                                    <div class="blueprint-import-review__field">
                                        <label class="label" for="import-draft-assessment">Jenis Asesmen</label>
                                        <select id="import-draft-assessment" class="ui-input" name="assessment_type" required>
                                            @foreach ($assessments as $assessment)
                                                <option value="{{ $assessment->value }}" @selected(old('assessment_type') === $assessment->value)>{{ $assessment->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="blueprint-import-review__field">
                                        <p class="label">Mode</p>
                                        @foreach ($modes as $mode)
                                            <label class="blueprint-import-review__radio">
                                                <input type="radio" name="mode" value="{{ $mode->value }}" @checked(old('mode', 'simple') === $mode->value) @disabled($mode->value === 'advanced' && ! $isPro)>
                                                {{ $mode->label() }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="blueprint-import-review__selection-summary">
                                    <p id="import-selection-count">0 kandidat dipilih</p>
                                </div>

                                @foreach ($review->candidates as $candidate)
                                    @if (! in_array($candidate['index'], $convertibleIndexes, true))
                                        @continue
                                    @endif
                                    <div class="blueprint-import-review__row">
                                        <label class="blueprint-import-review__row-select">
                                            <input type="checkbox" name="selected_indexes[]" value="{{ $candidate['index'] }}" data-import-selection>
                                            <span>Kandidat {{ $candidate['index'] + 1 }}</span>
                                        </label>
                                        <input type="hidden" name="rows[{{ $candidate['index'] }}][index]" value="{{ $candidate['index'] }}">

                                        <div class="blueprint-import-review__row-context">
                                            <p class="label">Teks dari dokumen</p>
                                            <ul>
                                                @foreach ($candidate['fields'] as $field)
                                                    <li><strong>{{ $field['label'] }}:</strong> {{ $field['value'] }}</li>
                                                @endforeach
                                            </ul>
                                        </div>

                                        <div class="blueprint-import-review__row-fields">
                                            <div class="blueprint-import-review__field">
                                                <label class="label" for="import-row-{{ $candidate['index'] }}-cognitive_level">Level Kognitif</label>
                                                <select class="ui-input" id="import-row-{{ $candidate['index'] }}-cognitive_level" name="rows[{{ $candidate['index'] }}][cognitive_level]" required>
                                                    @foreach ($cognitiveLevels as $level)
                                                        <option value="{{ $level->value }}">{{ $level->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="blueprint-import-review__field">
                                                <label class="label" for="import-row-{{ $candidate['index'] }}-difficulty">Tingkat Kesulitan</label>
                                                <select class="ui-input" id="import-row-{{ $candidate['index'] }}-difficulty" name="rows[{{ $candidate['index'] }}][difficulty]" required>
                                                    @foreach ($difficulties as $difficulty)
                                                        <option value="{{ $difficulty->value }}">{{ $difficulty->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="blueprint-import-review__field">
                                                <label class="label" for="import-row-{{ $candidate['index'] }}-question_type">Bentuk soal</label>
                                                <select class="ui-input" id="import-row-{{ $candidate['index'] }}-question_type" name="rows[{{ $candidate['index'] }}][question_type]" required>
                                                    @foreach ($questionTypes as $type)
                                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="blueprint-import-review__field">
                                                <label class="label" for="import-row-{{ $candidate['index'] }}-requested_count">Jumlah Soal</label>
                                                <input class="ui-input" id="import-row-{{ $candidate['index'] }}-requested_count" type="number" min="1" max="10" name="rows[{{ $candidate['index'] }}][requested_count]" value="1" required>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                                <x-ui.button class="blueprint-import-review__submit" type="submit" id="import-submit-btn" disabled>Simpan sebagai draf</x-ui.button>
                            </form>
                        @elseif (! $import->created_blueprint_id && $import->grounding_status?->value === 'ready')
                            <div class="blueprint-import-review__zero-panel">
                                <p>Pencocokan selesai.</p>
                                <p>Tidak ada kandidat yang dapat dipakai.</p>
                            </div>
                        @endif
                    </div>
                </div>
            @elseif ($review->interpretationStatus === null && $review->extractionStatus === 'extracted')
                <x-ui.alert variant="info" class="blueprint-import-review__banner">Impor ini belum memiliki hasil interpretasi.</x-ui.alert>
            @endif

            @if (! $import->created_blueprint_id && $import->grounding_status?->value === 'ready' && $convertibleIndexes === [] && ! ($review->interpretationStatus === 'review_ready' && $review->resultValid && ! $review->presentationError))
                <div class="blueprint-import-review__zero-panel">
                    <p>Pencocokan selesai.</p>
                    <p>Tidak ada kandidat yang dapat dipakai.</p>
                </div>
            @endif
        </div>
@endsection

@if (! $import->created_blueprint_id && $import->grounding_status?->value === 'ready' && $convertibleIndexes !== [])
    @push('scripts')
        <script>
            (function () {
                var checkboxes = document.querySelectorAll('[data-import-selection]');
                var summary = document.getElementById('import-selection-count');
                var submit = document.getElementById('import-submit-btn');

                function sync() {
                    var count = 0;
                    for (var i = 0; i < checkboxes.length; i++) {
                        if (checkboxes[i].checked) {
                            count += 1;
                        }
                    }
                    if (summary) {
                        summary.textContent = count + ' kandidat dipilih';
                    }
                    if (submit) {
                        submit.disabled = count === 0;
                    }
                }

                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].addEventListener('change', sync);
                }
                sync();
            })();
        </script>
    @endpush
@endif

@if ($review->isInFlight() || $groundingInFlight)
    @push('scripts')
        <script>
            (function () {
                var statusUrl = @json(route('materials.blueprint-imports.status', [$material, $import]));
                var intervalMs = @json($pollIntervalMs);
                var initialExtraction = @json($review->extractionStatus);
                var initialInterpretation = @json($review->interpretationStatus);
                var initialGrounding = @json($import->grounding_status?->value);
                var live = document.getElementById('import-state-live');
                var failures = 0;
                var maxFailures = 3;

                function retry() {
                    failures += 1;

                    if (failures >= maxFailures) {
                        return;
                    }

                    window.setTimeout(poll, intervalMs);
                }

                function poll() {
                    fetch(statusUrl, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store'
                    }).then(function (response) {
                        if (response.status === 401 || response.status === 403 || response.status === 404) {
                            return null;
                        }

                        if (! response.ok) {
                            retry();

                            return null;
                        }

                        failures = 0;

                        return response.json();
                    }).then(function (data) {
                        if (! data) {
                            return;
                        }

                        var extractionLabels = {
                            pending: 'Menunggu ekstraksi',
                            processing: 'Sedang diekstraksi',
                            extracted: 'Ekstraksi selesai',
                            failed: 'Ekstraksi gagal'
                        };
                        var interpretationLabels = {
                            queued: 'Interpretasi mengantri',
                            processing: 'Interpretasi diproses',
                            review_ready: 'Siap ditinjau',
                            failed: 'Interpretasi gagal'
                        };

                        if (live) {
                            live.textContent = 'Proses masih berjalan. Status ekstraksi: '
                                + (extractionLabels[data.extraction_status] || 'Status tidak dikenali')
                                + '; interpretasi: '
                                + (interpretationLabels[data.interpretation_status] || 'belum ada')
                                + '.';
                        }

                        if (
                            data.terminal
                            || data.extraction_status !== initialExtraction
                            || data.interpretation_status !== initialInterpretation
                            || data.grounding_status !== initialGrounding
                        ) {
                            window.location.reload();

                            return;
                        }

                        window.setTimeout(poll, intervalMs);
                    }).catch(function () {
                        retry();
                    });
                }

                window.setTimeout(poll, intervalMs);
            })();
        </script>
    @endpush
@endif
