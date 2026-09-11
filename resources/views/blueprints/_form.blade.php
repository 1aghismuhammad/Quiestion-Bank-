@php
    $mappingOptions = $mappingOptions ?? ['elements' => [], 'chunks' => []];
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

<p class="muted" style="margin-top: 16px;">Semua baris harus pilihan ganda dan satu tingkat kesulitan. Total soal 1–10. Setiap baris wajib memilih sumber konteks dari profil materi.</p>

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
    })();
</script>
