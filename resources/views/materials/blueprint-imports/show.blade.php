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

        @if ($review->isInFlight())
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

        @if ($review->canRetry)
            <form method="POST" action="{{ route('materials.blueprint-imports.retry', [$material, $import]) }}" style="margin-top: 12px;">
                @csrf
                <button class="button" type="submit">Coba ulang interpretasi</button>
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
@endsection

@if ($review->isInFlight())
    @push('scripts')
        <script>
            (function () {
                var statusUrl = @json(route('materials.blueprint-imports.status', [$material, $import]));
                var intervalMs = @json($pollIntervalMs);
                var initialExtraction = @json($review->extractionStatus);
                var initialInterpretation = @json($review->interpretationStatus);
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

                        if (live) {
                            live.textContent = 'Proses masih berjalan. Status ekstraksi: '
                                + data.extraction_status
                                + '; interpretasi: '
                                + (data.interpretation_status || 'belum ada')
                                + '.';
                        }

                        if (
                            data.terminal
                            || data.extraction_status !== initialExtraction
                            || data.interpretation_status !== initialInterpretation
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
