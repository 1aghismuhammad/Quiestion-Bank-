<div class="card" style="margin-top: 12px;" data-blueprint-row>
    <div class="actions" style="justify-content: space-between;">
        <p data-row-heading><strong>Baris {{ is_numeric($index) ? $index + 1 : $index }}</strong></p>
        @if ($canRemove ?? false)
            <button class="button button-secondary" type="button" data-remove-row>Hapus baris</button>
        @endif
    </div>
    <label class="label">Tujuan</label>
    <input class="input" name="rows[{{ $index }}][objective]" value="{{ $row['objective'] ?? '' }}" required>
    <label class="label">Topik</label>
    <input class="input" name="rows[{{ $index }}][topic]" value="{{ $row['topic'] ?? '' }}" required>
    <label class="label">Indikator</label>
    <input class="input" name="rows[{{ $index }}][indicator]" value="{{ $row['indicator'] ?? '' }}" required>
    <div class="field-grid">
        <div>
            <label class="label">Level kognitif</label>
            <select class="input" name="rows[{{ $index }}][cognitive_level]" required>
                @foreach ($cognitiveLevels as $level)
                    <option value="{{ $level->value }}" @selected(($row['cognitive_level'] ?? '') === $level->value)>{{ $level->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">Kesulitan</label>
            <select class="input" name="rows[{{ $index }}][difficulty]" required>
                @foreach ($difficulties as $difficulty)
                    <option value="{{ $difficulty->value }}" @selected(($row['difficulty'] ?? '') === $difficulty->value)>{{ $difficulty->value }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">Jumlah soal</label>
            <input class="input" type="number" min="1" max="10" name="rows[{{ $index }}][requested_count]" value="{{ $row['requested_count'] ?? 1 }}" required>
        </div>
    </div>
    <label class="label" for="row-source-{{ $index }}">Sumber konteks</label>
    <select class="input" id="row-source-{{ $index }}" name="rows[{{ $index }}][sources][]" required>
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
    @error('rows.'.$index.'.sources')
        <div class="error-text">{{ $message }}</div>
    @enderror
</div>
