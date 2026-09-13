@extends('layouts.app')

@section('title', 'Kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke materi</a>
    </div>

    <p class="muted">KISI-KISI</p>
    <h1>{{ $material->title }}</h1>

    <div class="actions" style="margin-bottom: 20px;">
        <a class="button" href="{{ route('materials.blueprints.create', $material) }}">Buat kisi-kisi manual</a>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h2>Isi dengan AI</h2>
        <form method="POST" action="{{ route('materials.blueprints.ai', $material) }}">
            @csrf
            <label style="display: block; margin-top: 8px;">
                <input type="radio" name="mode" value="simple" checked>
                Sederhana
            </label>
            <label style="display: block; margin-top: 8px;">
                <input type="radio" name="mode" value="advanced" id="ai-mode-advanced" @disabled(! $isPro)>
                Lanjutan (Pro)
            </label>
            @if (! $isPro)
                <p class="muted">Mode lanjutan terkunci untuk paket Free atau Pro yang sudah berakhir.</p>
            @endif
            <div id="ai-target-wrap" style="margin-top: 12px;" hidden>
                <label class="label" for="target_total">Jumlah soal yang diusulkan (1–{{ $maxAdvancedTotal }})</label>
                <input class="input" id="target_total" name="target_total" type="number" min="1" max="{{ $maxAdvancedTotal }}" value="15">
            </div>
            <div style="margin-top: 12px;">
                <button class="button button-secondary" type="submit">Isi dengan AI</button>
            </div>
        </form>
    </div>

    @if ($readyProfile === null)
        <div class="alert alert-error">
            Profil materi yang siap diperlukan sebelum kisi-kisi dapat dibuat atau dikonfirmasi.
            <a href="{{ route('materials.profile.show', $material) }}">Buka analisis profil</a>
        </div>
    @endif

    <div class="card">
        @if ($blueprints->isEmpty())
            <p class="muted">Belum ada kisi-kisi untuk materi ini.</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Sumber</th>
                        <th>Baris</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blueprints as $blueprint)
                        <tr>
                            <td>
                                <a href="{{ route('materials.blueprints.show', [$material, $blueprint]) }}">{{ $blueprint->title }}</a>
                            </td>
                            <td>{{ $blueprint->mode->label() }}</td>
                            <td>{{ $blueprint->lifecycle_status->value }}</td>
                            <td>{{ $blueprint->source->value }}</td>
                            <td>{{ $blueprint->rows->count() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <script>
        (function () {
            const advanced = document.getElementById('ai-mode-advanced');
            const wrap = document.getElementById('ai-target-wrap');
            const target = document.getElementById('target_total');

            function sync() {
                const on = advanced && advanced.checked;
                wrap.hidden = ! on;
                target.disabled = ! on;
            }

            document.querySelectorAll('input[name="mode"]').forEach(function (input) {
                input.addEventListener('change', sync);
            });
            sync();
        })();
    </script>
@endsection
