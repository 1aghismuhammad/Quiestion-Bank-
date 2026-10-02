@extends('layouts.app')

@section('title', 'Kisi-kisi')

@section('content')
    <div class="blueprint-hub-page">
    <x-ui.page-header>
        Kisi-kisi
        <x-slot:back>
            <a class="blueprint-hub-back" href="{{ route('materials.show', $material) }}">
                <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                <span>Kembali ke materi</span>
            </a>
        </x-slot:back>
        <x-slot:supporting>{{ $material->title }}</x-slot:supporting>
    </x-ui.page-header>
    <p class="blueprint-hub-lead">Pilih cara membuat kisi-kisi sesuai kebutuhan Anda.</p>

    @if ($readyProfile === null)
        <x-ui.alert variant="danger">
            Materi perlu memiliki profil yang siap sebelum kisi-kisi dapat dibuat atau dikonfirmasi.
            <x-ui.button variant="tertiary" href="{{ route('materials.profile.show', $material) }}">Buka Analisis Profil</x-ui.button>
        </x-ui.alert>
    @endif

    <div class="blueprint-hub-methods">
    <x-ui.panel class="creation-card">
        <h2>Buat manual</h2>
        <p class="creation-copy">Susun kisi-kisi sendiri dari awal sesuai kebutuhan Anda.</p>
        <div class="creation-card-actions">
            @if ($readyProfile === null)
                <button class="ui-button ui-button-secondary" type="button" disabled>Buat manual</button>
            @else
                <x-ui.button variant="secondary" href="{{ route('materials.blueprints.create', $material) }}">Buat manual</x-ui.button>
            @endif
        </div>
    </x-ui.panel>

    <x-ui.panel class="creation-card">
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
                <select class="ui-input" id="question_type" name="question_type">
                    @foreach ($questionTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <label class="label" for="simple_target_total" style="margin-top: 12px;">Jumlah Soal (1–{{ $maxSimpleTotal }})</label>
                <input class="ui-input" id="simple_target_total" name="target_total" type="number" min="1" max="{{ $maxSimpleTotal }}" value="10">
            </div>
            <div id="ai-advanced-wrap" style="margin-top: 12px;" hidden>
                <p class="muted">Isi jumlah per tipe. Total 1–{{ $maxAdvancedTotal }}.</p>
                <label class="label" for="count_mcq">Pilihan Ganda</label>
                <input class="ui-input" id="count_mcq" name="type_counts[multiple_choice]" type="number" min="0" max="{{ $maxAdvancedTotal }}" value="0" disabled>
                <label class="label" for="count_tf">Benar/Salah</label>
                <input class="ui-input" id="count_tf" name="type_counts[true_false]" type="number" min="0" max="{{ $maxAdvancedTotal }}" value="0" disabled>
                <label class="label" for="count_essay">Esai</label>
                <input class="ui-input" id="count_essay" name="type_counts[essay]" type="number" min="0" max="{{ $maxAdvancedTotal }}" value="0" disabled>
                <p style="margin-top: 8px;"><strong>Total:</strong> <span data-ai-live-total>0</span></p>
                <input type="hidden" id="advanced_target_total" name="target_total" value="0" disabled>
            </div>
            <div class="creation-card-actions">
                <x-ui.button variant="secondary" type="submit" :disabled="$readyProfile === null">Buat dengan AI</x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    <x-ui.panel class="creation-card">
        <h2>Unggah DOCX</h2>
        <p class="creation-copy">Sudah memiliki kisi-kisi? Unggah file DOCX untuk ditinjau dan disesuaikan dengan materi.</p>
        <form method="POST" action="{{ route('materials.blueprint-imports.store', $material) }}" enctype="multipart/form-data">
            @csrf
            <div class="file-picker">
                <input id="blueprint-import-file" class="file-picker-input" type="file" name="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required @disabled($readyProfile === null)>
                @if ($readyProfile === null)
                    <span class="ui-button ui-button-secondary" aria-disabled="true">Pilih file DOCX</span>
                @else
                    <label class="ui-button ui-button-secondary" for="blueprint-import-file">Pilih file DOCX</label>
                @endif
                <p class="muted file-picker-name" id="blueprint-import-file-name">Belum ada file dipilih</p>
            </div>
            @error('file')
                <div class="error-text">{{ $message }}</div>
            @enderror
            @error('material')
                <div class="error-text">{{ $message }}</div>
            @enderror
            <div class="creation-card-actions">
                <x-ui.button variant="secondary" type="submit" :disabled="$readyProfile === null">Unggah DOCX</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
    </div>

    <x-ui.panel class="blueprint-hub-list">
        <h2>Kisi-kisi materi ini</h2>
        @if ($blueprints->isEmpty())
            <p>Belum ada kisi-kisi.</p>
        @else
            <div class="responsive-table table-wrap">
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
                                    @if ($blueprint->lifecycle_status->value === 'confirmed')
                                        <x-ui.status-badge variant="success">Dikonfirmasi</x-ui.status-badge>
                                    @elseif ($blueprint->lifecycle_status->value === 'draft')
                                        <x-ui.status-badge variant="neutral">Draf</x-ui.status-badge>
                                    @else
                                        <x-ui.status-badge>Status tidak dikenali</x-ui.status-badge>
                                    @endif
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

            <div class="responsive-summary">
                @foreach ($blueprints as $blueprint)
                    <article class="summary-row">
                        <strong><a href="{{ route('materials.blueprints.show', [$material, $blueprint]) }}">{{ $blueprint->title }}</a></strong>
                        <p class="muted">{{ $blueprint->mode->label() }} · {{ match ($blueprint->source->value) {
                            'manual' => 'Manual',
                            'ai' => 'AI',
                            default => 'Sumber tidak dikenali',
                        } }} · {{ $blueprint->rows->count() }} baris</p>
                        @if ($blueprint->lifecycle_status->value === 'confirmed')
                            <x-ui.status-badge variant="success">Dikonfirmasi</x-ui.status-badge>
                        @elseif ($blueprint->lifecycle_status->value === 'draft')
                            <x-ui.status-badge variant="neutral">Draf</x-ui.status-badge>
                        @else
                            <x-ui.status-badge>Status tidak dikenali</x-ui.status-badge>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>

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
    </div>
@endsection
