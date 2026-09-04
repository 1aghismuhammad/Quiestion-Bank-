@php
    use App\Enums\MaterialProfileElementKind;
    use App\Enums\MaterialProfileOwnerState;

    $state = $profile->state;

    $stateLabels = [
        'none' => 'Belum dianalisis',
        'queued' => 'Menunggu antrian',
        'processing' => 'Sedang dianalisis',
        'ready' => 'Siap',
        'failed' => 'Gagal',
        'stale' => 'Tidak sesuai konten terbaru',
    ];

    $stateClasses = [
        'none' => 'status status-muted',
        'queued' => 'status status-warn',
        'processing' => 'status status-warn',
        'ready' => 'status',
        'failed' => 'status status-error',
        'stale' => 'status status-warn',
    ];

    $purposeLabels = [
        'map' => 'membaca bagian materi',
        'reduce' => 'menyusun rangkuman profil',
    ];

    $kindLabels = [
        'topic' => 'Topik dan cakupan materi',
        'objective' => 'Tujuan pembelajaran (capaian pembelajaran)',
        'indicator' => 'Indikator terukur',
        'other' => 'Ketentuan instruksional lain',
    ];
@endphp

@extends('layouts.app')

@section('title', 'Profil materi: '.$material->title)

@section('content')
    <style>
        @media (prefers-reduced-motion: reduce) {
            .profile-progress,
            .profile-progress::-webkit-progress-value {
                transition: none !important;
                animation: none !important;
            }
        }
    </style>

    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke materi</a>
        <a href="{{ route('materials.index') }}">Daftar materi</a>
    </div>

    <p class="muted">PROFIL MATERI</p>
    <h1>{{ $material->title }}</h1>

    <div class="card" style="margin-bottom: 20px;">
        <h2>Status analisis</h2>
        <p>
            <strong>Status:</strong>
            <span class="{{ $stateClasses[$state->value] }}" id="profile-state-label">
                {{ $stateLabels[$state->value] }}
            </span>
        </p>

        <p aria-live="polite" id="profile-state-live" role="status">
            @if ($profile->isInFlight())
                Analisis berjalan. Langkah selesai: {{ $profile->completedSteps }} dari {{ $profile->totalSteps }}.
            @else
                Status saat ini: {{ $stateLabels[$state->value] }}.
            @endif
        </p>

        @if ($profile->totalSteps > 0)
            <p>
                <label class="label" for="profile-progress">Langkah selesai</label>
                <progress
                    class="profile-progress"
                    id="profile-progress"
                    max="{{ $profile->totalSteps }}"
                    value="{{ $profile->completedSteps }}"
                ></progress>
                <span id="profile-progress-text">{{ $profile->completedSteps }} dari {{ $profile->totalSteps }} langkah</span>
            </p>
        @endif

        @if ($profile->isInFlight() && $profile->activePurpose !== null)
            <p class="muted">Tahap saat ini: {{ $purposeLabels[$profile->activePurpose->value] ?? $profile->activePurpose->value }}.</p>
        @endif

        @if ($profile->version?->queued_at)
            <p class="muted">Antrian {{ $profile->version->queued_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
        @endif
        @if ($profile->version?->completed_at)
            <p class="muted">Selesai {{ $profile->version->completed_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
        @endif

        @if ($profile->isInFlight())
            <p class="muted">Halaman akan diperbarui otomatis. Jika JavaScript dimatikan, muat ulang halaman secara manual.</p>
            <noscript>
                <p>Muat ulang halaman untuk melihat status analisis terbaru.</p>
            </noscript>
            <p>
                <a class="button button-secondary" href="{{ route('materials.profile.show', $material) }}">Muat ulang status</a>
            </p>
        @endif
    </div>

    @if ($state === MaterialProfileOwnerState::None)
        <div class="card" style="margin-bottom: 20px;">
            <h2>Apa itu analisis profil materi?</h2>
            <p>
                Analisis profil membaca materi Anda bagian demi bagian, lalu menyusun ringkasan pedagogis
                berupa topik dan cakupan materi, tujuan pembelajaran, serta indikator terukur.
            </p>
            <p>
                Setiap temuan yang diambil langsung dari materi disertai kutipan sumbernya, sehingga Anda dapat
                memeriksa dasar setiap butir. Analisis ini tidak memotong kuota generasi soal.
            </p>

            @if ($profile->canStart)
                <form method="POST" action="{{ route('materials.profile.store', $material) }}">
                    @csrf
                    <button class="button" type="submit">Mulai analisis profil</button>
                </form>
            @else
                <p class="status status-warn">Materi belum bisa dianalisis</p>
                <p class="muted">
                    Analisis profil hanya tersedia untuk materi berstatus siap yang sudah memiliki konten teks
                    dan tidak diarsipkan. Pastikan ekstraksi materi sudah selesai lebih dahulu.
                </p>
            @endif
        </div>
    @endif

    @if ($state === MaterialProfileOwnerState::Queued || $state === MaterialProfileOwnerState::Processing)
        <div class="card" style="margin-bottom: 20px;">
            <h2>{{ $state === MaterialProfileOwnerState::Queued ? 'Analisis diterima' : 'Analisis sedang berjalan' }}</h2>
            <p>
                @if ($state === MaterialProfileOwnerState::Queued)
                    Permintaan analisis sudah diterima dan menunggu diproses.
                @else
                    Materi sedang dianalisis. Proses ini berjalan di latar belakang.
                @endif
            </p>
            <p class="muted">Anda dapat menutup halaman ini dan kembali nanti.</p>

            @if ($profile->previousReady !== null)
                <h3>Profil sebelumnya</h3>
                <p class="status status-muted">Profil lama, bukan hasil analisis yang sedang berjalan</p>
                <p class="muted">
                    Versi {{ $profile->previousReady->version }} masih tersedia dan cocok dengan konten materi saat ini.
                    Hasil analisis baru akan menggantikan tampilan ini setelah selesai.
                </p>
            @endif
        </div>
    @endif

    @if ($state === MaterialProfileOwnerState::Ready)
        <div class="card" style="margin-bottom: 20px;">
            <h2>Hasil profil materi</h2>
            <p class="status">Profil terkini untuk konten materi saat ini</p>
            <p class="muted">
                Versi profil {{ $profile->version?->version }}. Butir bertanda &ldquo;Dari materi&rdquo; memiliki
                kutipan sumber. Butir bertanda &ldquo;Saran&rdquo; adalah rangkuman yang disusun dari butir-butir tersebut
                dan tidak memiliki kutipan langsung.
            </p>

            @if (! $profile->hasAnyElements())
                <p class="muted">Profil ini tidak memiliki butir untuk ditampilkan.</p>
            @endif
        </div>

        @foreach (MaterialProfileElementKind::cases() as $kind)
            @if ($profile->hasElementsOfKind($kind))
                <div class="card" style="margin-bottom: 20px;">
                    <h3>{{ $kindLabels[$kind->value] ?? $kind->value }}</h3>

                    @include('materials.profile._element-list', [
                        'items' => $profile->extracted($kind),
                        'originLabel' => 'Dari materi',
                        'originClass' => 'status',
                        'withEvidence' => true,
                    ])

                    @include('materials.profile._element-list', [
                        'items' => $profile->suggested($kind),
                        'originLabel' => 'Saran',
                        'originClass' => 'status status-muted',
                        'withEvidence' => false,
                    ])
                </div>
            @endif
        @endforeach
    @endif

    @if ($state === MaterialProfileOwnerState::Failed)
        <div class="card" style="margin-bottom: 20px;">
            <h2>Analisis tidak selesai</h2>
            <p>{{ $profile->errorMessage ?? \App\Support\MaterialProfiles\MaterialProfileOwnerMessages::GENERIC }}</p>
            <p class="muted">
                Riwayat analisis sebelumnya tetap tersimpan. Menjalankan analisis baru akan membuat versi profil baru
                tanpa mengubah versi lama.
            </p>
        </div>
    @endif

    @if ($state === MaterialProfileOwnerState::Stale)
        <div class="card" style="margin-bottom: 20px;">
            <h2>Profil tidak sesuai konten terbaru</h2>
            <p class="status status-warn">Bukan profil terkini</p>
            <p>
                Konten materi berubah setelah profil versi {{ $profile->version?->version }} dibuat, sehingga hasil lama
                tidak lagi mewakili materi ini. Karena itu hasilnya tidak ditampilkan sebagai profil terkini.
            </p>
            <p class="muted">Profil versi lama tetap tersimpan apa adanya dan tidak diubah.</p>
        </div>
    @endif

    @if ($profile->canStart || $profile->canRegenerate)
        <div class="card">
            <h2>{{ $profile->canRegenerate ? 'Analisis ulang' : 'Mulai analisis' }}</h2>
            <p class="muted">
                Maksimal tiga analisis profil baru per jam. Analisis profil tidak memotong kuota generasi soal.
            </p>

            @if ($profile->canRegenerate)
                <form method="POST" action="{{ route('materials.profile.regenerate', $material) }}">
                    @csrf
                    <button class="button" type="submit">Jalankan analisis baru</button>
                </form>
            @else
                <form method="POST" action="{{ route('materials.profile.store', $material) }}">
                    @csrf
                    <button class="button" type="submit">Mulai analisis profil</button>
                </form>
            @endif
        </div>
    @endif
@endsection

@if ($profile->isInFlight())
    @push('scripts')
        <script>
            (function () {
                var statusUrl = @json(route('materials.profile.status', $material));
                var intervalMs = @json($pollIntervalMs);
                var initialState = @json($profile->state->value);
                var live = document.getElementById('profile-state-live');
                var progress = document.getElementById('profile-progress');
                var progressText = document.getElementById('profile-progress-text');
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
                        credentials: 'same-origin'
                    }).then(function (response) {
                        // Authorization or routing failures stop polling entirely.
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
                            live.textContent = 'Analisis berjalan. Langkah selesai: '
                                + data.completed_steps + ' dari ' + data.total_steps + '.';
                        }

                        if (progress && data.total_steps > 0) {
                            progress.max = data.total_steps;
                            progress.value = data.completed_steps;
                        }

                        if (progressText && data.total_steps > 0) {
                            progressText.textContent = data.completed_steps + ' dari ' + data.total_steps + ' langkah';
                        }

                        if (data.terminal || data.state !== initialState) {
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
