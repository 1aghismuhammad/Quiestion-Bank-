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
            <div id="ai-simple-wrap" style="margin-top: 12px;">
                <label class="label" for="question_type">Tipe soal</label>
                <select class="input" id="question_type" name="question_type">
                    @foreach ($questionTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <label class="label" for="simple_target_total" style="margin-top: 12px;">Jumlah soal (1–{{ $maxSimpleTotal }})</label>
                <input class="input" id="simple_target_total" name="target_total" type="number" min="1" max="{{ $maxSimpleTotal }}" value="10">
            </div>
            <div id="ai-advanced-wrap" style="margin-top: 12px;" hidden>
                <p class="muted">Isi jumlah per tipe. Total 1–{{ $maxAdvancedTotal }}.</p>
                <label class="label" for="count_mcq">Pilihan Ganda</label>
                <input class="input" id="count_mcq" name="type_counts[multiple_choice]" type="number" min="0" max="{{ $maxAdvancedTotal }}" value="0" disabled>
                <label class="label" for="count_tf">Benar/Salah</label>
                <input class="input" id="count_tf" name="type_counts[true_false]" type="number" min="0" max="{{ $maxAdvancedTotal }}" value="0" disabled>
                <label class="label" for="count_essay">Esai</label>
                <input class="input" id="count_essay" name="type_counts[essay]" type="number" min="0" max="{{ $maxAdvancedTotal }}" value="0" disabled>
                <p style="margin-top: 8px;"><strong>Total:</strong> <span data-ai-live-total>0</span></p>
                <input type="hidden" id="advanced_target_total" name="target_total" value="0" disabled>
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
            const simpleWrap = document.getElementById('ai-simple-wrap');
            const advancedWrap = document.getElementById('ai-advanced-wrap');
            const simpleTarget = document.getElementById('simple_target_total');
            const simpleType = document.getElementById('question_type');
            const advancedTarget = document.getElementById('advanced_target_total');
            const countInputs = [
                document.getElementById('count_mcq'),
                document.getElementById('count_tf'),
                document.getElementById('count_essay'),
            ];
            const liveTotal = document.querySelector('[data-ai-live-total]');

            function selectedAdvanced() {
                return advanced && advanced.checked;
            }

            function liveSum() {
                return countInputs.reduce(function (sum, input) {
                    return sum + (parseInt(input.value, 10) || 0);
                }, 0);
            }

            function syncCounts() {
                const total = liveSum();
                liveTotal.textContent = String(total);
                advancedTarget.value = String(total);
            }

            function sync() {
                const on = selectedAdvanced();
                simpleWrap.hidden = on;
                advancedWrap.hidden = ! on;
                simpleTarget.disabled = on;
                simpleType.disabled = on;
                advancedTarget.disabled = ! on;
                countInputs.forEach(function (input) {
                    input.disabled = ! on;
                });
                syncCounts();
            }

            countInputs.forEach(function (input) {
                input.addEventListener('input', syncCounts);
            });
            document.querySelectorAll('input[name="mode"]').forEach(function (input) {
                input.addEventListener('change', sync);
            });
            sync();
        })();
    </script>
@endsection
