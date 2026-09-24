@extends('layouts.app')

@section('title', 'Kisi-kisi')

@section('content')
    <style>
        .creation-card { margin-bottom: 16px; }
        .creation-card h2 { margin-bottom: 8px; }
        .creation-card .creation-copy { margin-bottom: 16px; }
        .file-picker { position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 8px; max-width: 100%; }
        .file-picker-input {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .file-picker-input:focus + .button { outline: 2px solid #2356d8; outline-offset: 2px; }
        .file-picker-name { margin: 0; max-width: 100%; overflow-wrap: anywhere; }
        .blueprint-table-wrap { max-width: 100%; overflow-x: auto; }
    </style>

    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke materi</a>
    </div>

    <p class="muted">KISI-KISI</p>
    <h1>{{ $material->title }}</h1>
    <h2>Buat Kisi-kisi</h2>
    <p class="muted">Pilih cara yang paling sesuai untuk membuat kisi-kisi dari materi ini.</p>

    @if ($readyProfile === null)
        <div class="alert alert-error">
            Materi perlu memiliki profil yang siap sebelum kisi-kisi dapat dibuat atau dikonfirmasi.
            <a href="{{ route('materials.profile.show', $material) }}">Buka Analisis Profil</a>
        </div>
    @endif

    <div class="card creation-card">
        <h2>Buat Manual</h2>
        <p class="creation-copy">Susun kisi-kisi sendiri dari awal sesuai kebutuhan Anda.</p>
        <a class="button" href="{{ route('materials.blueprints.create', $material) }}">Buat Kisi-kisi Manual</a>
    </div>

    <div class="card creation-card">
        <h2>Buat dengan AI</h2>
        <p class="creation-copy">Gunakan materi yang telah dianalisis untuk membantu menyusun kisi-kisi.</p>
        <form method="POST" action="{{ route('materials.blueprints.ai', $material) }}">
            @csrf
            <p class="label">Mode</p>
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
                <label class="label" for="question_type">Tipe Soal</label>
                <select class="input" id="question_type" name="question_type">
                    @foreach ($questionTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <label class="label" for="simple_target_total" style="margin-top: 12px;">Jumlah Soal (1–{{ $maxSimpleTotal }})</label>
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
            <div style="margin-top: 16px;">
                <button class="button" type="submit">Buat dengan AI</button>
            </div>
        </form>
    </div>

    <div class="card creation-card">
        <h2>Unggah Kisi-kisi DOCX</h2>
        <p class="creation-copy">Sudah memiliki kisi-kisi? Unggah file DOCX untuk ditinjau dan disesuaikan dengan materi.</p>
        <form method="POST" action="{{ route('materials.blueprint-imports.store', $material) }}" enctype="multipart/form-data">
            @csrf
            <div class="file-picker">
                <input id="blueprint-import-file" class="file-picker-input" type="file" name="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                <label class="button button-secondary" for="blueprint-import-file">Pilih file DOCX</label>
                <p class="muted file-picker-name" id="blueprint-import-file-name">Belum ada file dipilih</p>
            </div>
            @error('file')
                <div class="error-text">{{ $message }}</div>
            @enderror
            @error('material')
                <div class="error-text">{{ $message }}</div>
            @enderror
            <button class="button" style="margin-top: 12px;" type="submit">Unggah Kisi-kisi</button>
        </form>
    </div>

    <div class="card">
        <h2>Kisi-kisi materi ini</h2>
        @if ($blueprints->isEmpty())
            <p class="muted">Belum ada kisi-kisi untuk materi ini.</p>
        @else
            <div class="blueprint-table-wrap">
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
                                <td>
                                    {{ match ($blueprint->lifecycle_status->value) {
                                        'draft' => 'Draf',
                                        'confirmed' => 'Dikonfirmasi',
                                        default => 'Status tidak dikenali',
                                    } }}
                                </td>
                                <td>
                                    {{ match ($blueprint->source->value) {
                                        'manual' => 'Manual',
                                        'ai' => 'AI',
                                        default => 'Sumber tidak dikenali',
                                    } }}
                                </td>
                                <td>{{ $blueprint->rows->count() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
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
            const fileInput = document.getElementById('blueprint-import-file');
            const fileName = document.getElementById('blueprint-import-file-name');

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

            if (fileInput && fileName) {
                fileInput.addEventListener('change', function () {
                    const file = fileInput.files && fileInput.files[0];
                    fileName.textContent = file ? file.name : 'Belum ada file dipilih';
                });
            }
        })();
    </script>
@endsection
