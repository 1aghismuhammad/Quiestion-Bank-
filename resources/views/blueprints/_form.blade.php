@php
    $mappingOptions = $mappingOptions ?? ['elements' => [], 'chunks' => []];
    $isPro = $isPro ?? false;
    $selectedMode = old('mode', $blueprint?->mode->value ?? 'simple');
    $rows = old('rows', $blueprint?->rows?->map(function ($row) {
        $sources = $row->contexts->map(function ($context) {
            if ($context->profile_element_id !== null) {
                return 'element:'.$context->profile_element_id;
            }

            if ($context->profile_chunk_id !== null) {
                return 'chunk:'.$context->profile_chunk_id;
            }

            return null;
        })->filter()->values()->all();

        return [
            'objective' => $row->objective,
            'topic' => $row->topic,
            'indicator' => $row->indicator,
            'cognitive_level' => $row->cognitive_level->value,
            'difficulty' => $row->difficulty->value,
            'requested_count' => $row->requested_count,
            'sources' => $sources,
        ];
    })->all() ?? [[
        'objective' => '',
        'topic' => '',
        'indicator' => '',
        'cognitive_level' => 'understand',
        'difficulty' => 'medium',
        'requested_count' => 5,
        'sources' => [],
    ]]);
@endphp

<div>
    <p class="label">Mode kisi-kisi</p>
    <label style="display: block; margin-top: 8px;">
        <input type="radio" name="mode" value="simple" @checked($selectedMode === 'simple')>
        Sederhana
    </label>
    <label style="display: block; margin-top: 8px;">
        <input type="radio" name="mode" value="advanced" @checked($selectedMode === 'advanced') @disabled(! $isPro)>
        Lanjutan (Pro)
    </label>
    @if (! $isPro)
        <p class="muted">Mode lanjutan terkunci. Paket Pro aktif diperlukan untuk 11–30 soal, kesulitan campuran, dan pengacakan.</p>
    @endif
    @error('mode')
        <div class="error-text">{{ $message }}</div>
    @enderror
</div>

<div>
    <label class="label" for="title">Judul</label>
    <input class="input" id="title" name="title" value="{{ old('title', $blueprint->title ?? '') }}" required>
    @error('title')
        <div class="error-text">{{ $message }}</div>
    @enderror
</div>

<div style="margin-top: 12px;">
    <label class="label" for="assessment_type">Tipe assessment</label>
    <select class="input" id="assessment_type" name="assessment_type" required>
        @foreach ($assessments as $assessment)
            <option value="{{ $assessment->value }}" @selected(old('assessment_type', $blueprint?->assessment_type->value ?? 'formative') === $assessment->value)>
                {{ $assessment->value }}
            </option>
        @endforeach
    </select>
</div>

<p class="muted" id="blueprint-simple-help" style="margin-top: 16px;">Mode sederhana: semua baris pilihan ganda dan satu tingkat kesulitan. Total soal 1–10. Maksimal 5 baris, 1–10 soal per baris.</p>
<p class="muted" id="blueprint-advanced-help" style="margin-top: 16px;">Mode lanjutan: semua baris pilihan ganda. Total soal 1–30. Maksimal 5 baris, 1–10 soal per baris. Tingkat kesulitan boleh berbeda.</p>
<p id="blueprint-live-summary" style="margin-top: 8px;"><strong>Total soal:</strong> <span data-live-total>0</span> · <strong>Perkiraan kredit:</strong> <span data-live-credits>0</span></p>

<div id="blueprint-rows">
    @foreach ($rows as $index => $row)
        @include('blueprints._row', [
            'index' => $index,
            'row' => $row,
            'cognitiveLevels' => $cognitiveLevels,
            'difficulties' => $difficulties,
            'mappingOptions' => $mappingOptions,
            'canRemove' => count($rows) > 1,
        ])
    @endforeach
</div>

<div class="actions" style="margin-top: 12px;">
    <button class="button button-secondary" type="button" id="blueprint-add-row" @disabled(count($rows) >= $maxRows)>Tambah baris</button>
</div>

<template id="blueprint-row-template">
    @include('blueprints._row', [
        'index' => '__INDEX__',
        'row' => [
            'objective' => '',
            'topic' => '',
            'indicator' => '',
            'cognitive_level' => 'understand',
            'difficulty' => 'medium',
            'requested_count' => 5,
            'sources' => [],
        ],
        'cognitiveLevels' => $cognitiveLevels,
        'difficulties' => $difficulties,
        'mappingOptions' => $mappingOptions,
        'canRemove' => true,
    ])
</template>

<script>
    (function () {
        const maxRows = {{ (int) $maxRows }};
        const list = document.getElementById('blueprint-rows');
        const addButton = document.getElementById('blueprint-add-row');
        const template = document.getElementById('blueprint-row-template');
        const simpleHelp = document.getElementById('blueprint-simple-help');
        const advancedHelp = document.getElementById('blueprint-advanced-help');
        const totalNode = document.querySelector('[data-live-total]');
        const creditsNode = document.querySelector('[data-live-credits]');

        function selectedMode() {
            const checked = document.querySelector('input[name="mode"]:checked');
            return checked ? checked.value : 'simple';
        }

        function syncModeHelp() {
            const advanced = selectedMode() === 'advanced';
            simpleHelp.hidden = advanced;
            advancedHelp.hidden = ! advanced;
            updateLiveSummary();
        }

        function liveTotal() {
            let total = 0;
            list.querySelectorAll('input[name$="[requested_count]"]').forEach(function (input) {
                total += parseInt(input.value, 10) || 0;
            });
            return total;
        }

        function updateLiveSummary() {
            const total = liveTotal();
            totalNode.textContent = String(total);
            creditsNode.textContent = String(total < 1 ? 0 : Math.ceil(total / 10));
        }

        function reindex() {
            const cards = list.querySelectorAll('[data-blueprint-row]');
            cards.forEach(function (card, index) {
                card.querySelectorAll('[name]').forEach(function (input) {
                    input.name = input.name.replace(/rows\[[^\]]+\]/, 'rows[' + index + ']');
                });
                const heading = card.querySelector('[data-row-heading]');
                if (heading) {
                    heading.textContent = 'Baris ' + (index + 1);
                }
            });
            addButton.disabled = cards.length >= maxRows;
            syncRemoveButtons();
            updateLiveSummary();
        }

        function syncRemoveButtons() {
            const cards = list.querySelectorAll('[data-blueprint-row]');
            const canRemove = cards.length > 1;
            const prototype = template.content.querySelector('[data-remove-row]');

            cards.forEach(function (card) {
                let remove = card.querySelector('[data-remove-row]');

                if (! canRemove) {
                    if (remove) {
                        remove.remove();
                    }

                    return;
                }

                if (! remove && prototype) {
                    const headingRow = card.querySelector('.actions');
                    if (headingRow) {
                        headingRow.appendChild(prototype.cloneNode(true));
                    }
                }
            });
        }

        addButton.addEventListener('click', function () {
            if (list.querySelectorAll('[data-blueprint-row]').length >= maxRows) {
                return;
            }
            const fragment = template.content.cloneNode(true);
            list.appendChild(fragment);
            reindex();
        });

        list.addEventListener('click', function (event) {
            const button = event.target.closest('[data-remove-row]');
            if (!button) {
                return;
            }
            const cards = list.querySelectorAll('[data-blueprint-row]');
            if (cards.length <= 1) {
                return;
            }
            button.closest('[data-blueprint-row]').remove();
            reindex();
        });

        list.addEventListener('input', function (event) {
            if (event.target && event.target.name && event.target.name.indexOf('[requested_count]') !== -1) {
                updateLiveSummary();
            }
        });

        document.querySelectorAll('input[name="mode"]').forEach(function (input) {
            input.addEventListener('change', syncModeHelp);
        });

        syncModeHelp();
        reindex();
    })();
</script>
