@extends('layouts.app')

@section('title', 'Tinjauan impor kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke materi</a>
        <a href="{{ route('materials.blueprint-imports.index', $material) }}">Riwayat impor</a>
    </div>

    <p class="muted">TINJAUAN IMPOR</p>
    <h1>{{ $review->originalFileName }}</h1>

    @if (session('success'))
        <div class="alert alert-success" style="margin-top: 12px;">{{ session('success') }}</div>
    @endif

    <div class="card" style="margin-top: 16px; margin-bottom: 20px;" id="import-review-status">
        <p>
            <strong>Ekstraksi:</strong>
            <span class="status">{{ $review->extractionStatusLabel }}</span>
        </p>
        <p>
            <strong>Interpretasi:</strong>
            <span class="status">{{ $review->interpretationStatusLabel }}</span>
        </p>

        <p>
            <strong>Pencocokan:</strong>
            <span class="status">
                @include('materials.blueprint-imports._grounding-label', ['status' => $import->grounding_status?->value])
            </span>
        </p>

        @if ($review->isInFlight() || $groundingInFlight)
            <p class="muted" id="import-state-live">Proses masih berjalan. Halaman akan dimuat ulang saat selesai.</p>
        @endif

        @if ($review->interpretationStatus === 'failed')
            <p class="status status-error">Interpretasi gagal.</p>
            @if ($review->errorMessage)
                <p>{{ $review->errorMessage }}</p>
            @endif
        @endif

        @if ($review->extractionStatus === 'failed')
            <p class="status status-error">Ekstraksi gagal. Interpretasi tidak dijalankan.</p>
            @if ($import->error_message)
                <p>{{ $import->error_message }}</p>
            @endif
        @endif

        @if ($review->interpretationStatus === 'review_ready' && $import->grounding_status === null)
            <form method="POST" action="{{ route('materials.blueprint-imports.ground', [$material, $import]) }}" style="margin-top: 12px;">
                @csrf
                <p class="muted">Kisi-kisi akan dibandingkan dengan materi agar tujuan, topik, dan indikator memiliki acuan yang sesuai.</p>
                <button class="button" type="submit">Cocokkan dengan Materi</button>
            </form>
        @endif

        @if ($import->grounding_status?->value === 'failed')
            <form method="POST" action="{{ route('materials.blueprint-imports.retry-grounding', [$material, $import]) }}" style="margin-top: 12px;">
                @csrf
                <button class="button" type="submit">Coba Lagi</button>
            </form>
        @endif

        @if ($review->canRetry)
            <form method="POST" action="{{ route('materials.blueprint-imports.retry', [$material, $import]) }}" style="margin-top: 12px;">
                @csrf
                <button class="button" type="submit">Coba lagi interpretasi</button>
            </form>
        @endif
    </div>

    @if ($review->presentationError)
        <div class="card" style="margin-bottom: 20px;">
            <p class="status status-error">{{ $review->presentationError }}</p>
        </div>
    @elseif ($review->interpretationStatus === 'review_ready' && $review->resultValid)
        <div class="card" style="margin-bottom: 20px;">
            <h2>Klasifikasi dokumen</h2>
            <p>{{ $review->documentKindLabel }}</p>

            @if ($review->topLevelWarnings !== [])
                <h3>Peringatan</h3>
                <ul>
                    @foreach ($review->topLevelWarnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($candidates->total() === 0)
            <div class="card">
                <p class="muted">Tidak ada kandidat untuk ditinjau.</p>
            </div>
        @else
            <p class="muted" style="margin-bottom: 12px;">
                Menampilkan {{ $candidates->firstItem() }}–{{ $candidates->lastItem() }}
                dari {{ $candidates->total() }} kandidat
            </p>

            @foreach ($candidates as $candidate)
                <div class="card" style="margin-bottom: 16px;">
                    <h2>Kandidat {{ $candidate['index'] + 1 }}</h2>

                    <table class="table">
                        <tbody>
                            @foreach ($candidate['fields'] as $field)
                                <tr>
                                    <th style="width: 30%;">{{ $field['label'] }}</th>
                                    <td class="{{ $field['empty'] ? 'muted' : '' }}">{{ $field['value'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if ($candidate['unresolved'] !== [])
                        <h3>Belum teridentifikasi</h3>
                        <p class="muted">Belum berhasil diidentifikasi pada tahap interpretasi.</p>
                        <ul>
                            @foreach ($candidate['unresolved'] as $item)
                                <li>{{ $item['label'] }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($candidate['warnings'] !== [])
                        <h3>Peringatan kandidat</h3>
                        <ul>
                            @foreach ($candidate['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($candidate['provenance'] !== [])
                        <h3>Sumber</h3>
                        <ul>
                            @foreach ($candidate['provenance'] as $group)
                                <li>
                                    <strong>{{ $group['field_label'] }}:</strong>
                                    {{ implode('; ', $group['locations']) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach

            @if ($candidates->hasPages())
                <div class="actions" style="margin-top: 16px;">
                    @if ($candidates->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <a class="button button-secondary" href="{{ $candidates->previousPageUrl() }}">Sebelumnya</a>
                    @endif

                    @if ($candidates->hasMorePages())
                        <a class="button button-secondary" href="{{ $candidates->nextPageUrl() }}">Berikutnya</a>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        @endif
    @elseif ($review->interpretationStatus === null && $review->extractionStatus === 'extracted')
        <div class="card">
            <p class="muted">Impor ini belum memiliki hasil interpretasi.</p>
        </div>
    @endif

    @if ($import->created_blueprint_id)
        <div class="card" style="margin-top: 16px;">
            <p>Draf kisi-kisi dari impor ini sudah dibuat.</p>
            <a class="button" href="{{ route('materials.blueprints.show', [$material, $import->created_blueprint_id]) }}">Buka draf</a>
        </div>
    @elseif ($import->grounding_status?->value === 'ready' && $convertibleIndexes !== [])
        <form class="card" method="POST" action="{{ route('materials.blueprint-imports.convert', [$material, $import]) }}" style="margin-top: 16px;">
            @csrf
            <h2>Buat draf kisi-kisi</h2>
            <p class="muted">Pilih kandidat yang akan dipakai, lalu lengkapi isian kisi-kisi. Konteks materi ditentukan secara otomatis.</p>
            @error('import')
                <div class="error-text">{{ $message }}</div>
            @enderror
            @error('selected_indexes')
                <div class="error-text">{{ $message }}</div>
            @enderror
            <label class="label" for="import-draft-title">Judul</label>
            <input id="import-draft-title" class="input" name="title" value="{{ old('title') }}" required maxlength="120">
            <label class="label" for="import-draft-assessment">Jenis Asesmen</label>
            <select id="import-draft-assessment" class="input" name="assessment_type" required>
                @foreach ($assessments as $assessment)
                    <option value="{{ $assessment->value }}" @selected(old('assessment_type') === $assessment->value)>{{ $assessment->label() }}</option>
                @endforeach
            </select>
            <p class="label">Mode</p>
            @foreach ($modes as $mode)
                <label style="display: block; margin-top: 8px;">
                    <input type="radio" name="mode" value="{{ $mode->value }}" @checked(old('mode', 'simple') === $mode->value) @disabled($mode->value === 'advanced' && ! $isPro)>
                    {{ $mode->label() }}
                </label>
            @endforeach
            @foreach ($review->candidates as $candidate)
                @if (! in_array($candidate['index'], $convertibleIndexes, true))
                    @continue
                @endif
                <div class="card" style="margin-top: 12px;">
                    <label>
                        <input type="checkbox" name="selected_indexes[]" value="{{ $candidate['index'] }}">
                        Kandidat {{ $candidate['index'] + 1 }}
                    </label>
                    <input type="hidden" name="rows[{{ $candidate['index'] }}][index]" value="{{ $candidate['index'] }}">
                    <p class="label">Teks dari dokumen</p>
                    <ul>
                        @foreach ($candidate['fields'] as $field)
                            <li><strong>{{ $field['label'] }}:</strong> {{ $field['value'] }}</li>
                        @endforeach
                    </ul>
                    <label class="label">Level Kognitif</label>
                    <select class="input" name="rows[{{ $candidate['index'] }}][cognitive_level]" required>
                        @foreach ($cognitiveLevels as $level)
                            <option value="{{ $level->value }}">{{ $level->label() }}</option>
                        @endforeach
                    </select>
                    <label class="label">Tingkat Kesulitan</label>
                    <select class="input" name="rows[{{ $candidate['index'] }}][difficulty]" required>
                        @foreach ($difficulties as $difficulty)
                            <option value="{{ $difficulty->value }}">{{ $difficulty->label() }}</option>
                        @endforeach
                    </select>
                    <label class="label">Tipe Soal</label>
                    <select class="input" name="rows[{{ $candidate['index'] }}][question_type]" required>
                        @foreach ($questionTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <label class="label">Jumlah Soal</label>
                    <input class="input" type="number" min="1" max="10" name="rows[{{ $candidate['index'] }}][requested_count]" value="1" required>
                </div>
            @endforeach
            <button class="button" style="margin-top: 12px;" type="submit">Simpan sebagai Draf</button>
        </form>
    @endif
@endsection

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
