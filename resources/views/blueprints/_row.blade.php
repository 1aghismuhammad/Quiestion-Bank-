<div class="card" style="margin-top: 12px;" data-blueprint-row>
    <div class="actions" style="justify-content: space-between;">
        <p data-row-heading><strong>Baris {{ is_numeric($index) ? $index + 1 : $index }}</strong></p>
        @if ($canRemove ?? false)
            <button class="ui-button ui-button-secondary" type="button" data-remove-row>Hapus baris</button>
        @endif
    </div>
    <label class="label" for="blueprint-row-{{ $index }}-objective" data-row-field="objective">Tujuan Pembelajaran</label>
    <input class="ui-input" id="blueprint-row-{{ $index }}-objective" data-row-field="objective" name="rows[{{ $index }}][objective]" value="{{ $row['objective'] ?? '' }}" required>
    <label class="label" for="blueprint-row-{{ $index }}-topic" data-row-field="topic">Topik</label>
    <input class="ui-input" id="blueprint-row-{{ $index }}-topic" data-row-field="topic" name="rows[{{ $index }}][topic]" value="{{ $row['topic'] ?? '' }}" required>
    <label class="label" for="blueprint-row-{{ $index }}-indicator" data-row-field="indicator">Indikator Soal</label>
    <input class="ui-input" id="blueprint-row-{{ $index }}-indicator" data-row-field="indicator" name="rows[{{ $index }}][indicator]" value="{{ $row['indicator'] ?? '' }}" required>
    <div class="field-grid">
        <div>
            <label class="label" for="blueprint-row-{{ $index }}-cognitive_level" data-row-field="cognitive_level">Level Kognitif</label>
            <select class="ui-input" id="blueprint-row-{{ $index }}-cognitive_level" data-row-field="cognitive_level" name="rows[{{ $index }}][cognitive_level]" required>
                @foreach ($cognitiveLevels as $level)
                    <option value="{{ $level->value }}" @selected(($row['cognitive_level'] ?? '') === $level->value)>{{ $level->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="blueprint-row-{{ $index }}-difficulty" data-row-field="difficulty">Tingkat Kesulitan</label>
            <select class="ui-input" id="blueprint-row-{{ $index }}-difficulty" data-row-field="difficulty" name="rows[{{ $index }}][difficulty]" required>
                @foreach ($difficulties as $difficulty)
                    <option value="{{ $difficulty->value }}" @selected(($row['difficulty'] ?? '') === $difficulty->value)>{{ $difficulty->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="blueprint-row-{{ $index }}-question_type" data-row-field="question_type">Tipe Soal</label>
            <select class="ui-input" id="blueprint-row-{{ $index }}-question_type" data-row-field="question_type" name="rows[{{ $index }}][question_type]" required>
                @foreach ($questionTypes as $type)
                    <option value="{{ $type->value }}" @selected(($row['question_type'] ?? 'multiple_choice') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="blueprint-row-{{ $index }}-requested_count" data-row-field="requested_count">Jumlah Soal</label>
            <input class="ui-input" id="blueprint-row-{{ $index }}-requested_count" data-row-field="requested_count" type="number" min="1" max="10" name="rows[{{ $index }}][requested_count]" value="{{ $row['requested_count'] ?? 1 }}" required>
        </div>
    </div>
    @if (! empty($importLinked))
        <p class="label">Sumber konteks</p>
        @foreach ($row['sources'] ?? [] as $source)
            <input type="hidden" name="rows[{{ $index }}][sources][]" value="{{ $source }}">
        @endforeach
        <p class="muted">Konteks impor dipertahankan ({{ count($row['sources'] ?? []) }}).</p>
    @else
        <label class="label" for="blueprint-row-{{ $index }}-sources" data-row-field="sources">Sumber konteks</label>
        <select class="ui-input" id="blueprint-row-{{ $index }}-sources" data-row-field="sources" name="rows[{{ $index }}][sources][]" required>
            <option value="">Pilih cuplikan profil</option>
            @foreach ($mappingOptions['elements'] as $element)
                <option value="element:{{ $element['id'] }}" @selected(in_array('element:'.$element['id'], $row['sources'] ?? [], true))>
                    {{ $element['label'] }}
                </option>
            @endforeach
            @foreach ($mappingOptions['chunks'] as $chunk)
                <option value="chunk:{{ $chunk['id'] }}" @selected(in_array('chunk:'.$chunk['id'], $row['sources'] ?? [], true))>
                    {{ $chunk['label'] }}
                </option>
            @endforeach
        </select>
    @endif
    @error('rows.'.$index.'.sources')
        <div class="error-text">{{ $message }}</div>
    @enderror
</div>
