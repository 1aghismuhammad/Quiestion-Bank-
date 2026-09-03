@php
    $questionsInput = old('questions');
@endphp

@extends('layouts.app')

@section('title', 'Edit soal')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('question-sets.show', $questionSet) }}">Kembali ke detail</a>
    </div>

    <p class="muted">EDIT QUESTION BANK</p>
    <h1>Edit soal</h1>

    @error('questions')
        <div class="error-text">{{ $message }}</div>
    @enderror
    @error('status')
        <div class="error-text">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('question-sets.update', $questionSet) }}">
        @csrf
        @method('PATCH')

        <div class="card" style="margin-bottom: 20px;">
            <label class="label" for="title">Judul</label>
            <input class="input" id="title" name="title" type="text" value="{{ old('title', $questionSet->title) }}" required maxlength="255">
            @error('title')
                <div class="error-text">{{ $message }}</div>
            @enderror
        </div>

        @foreach ($questionSet->questions as $index => $question)
            @php
                $correctLabel = $question->options->firstWhere('is_correct', true)?->option_label ?? 'A';
                $oldQuestion = is_array($questionsInput) ? ($questionsInput[$index] ?? []) : [];
            @endphp
            <div class="card" style="margin-bottom: 16px;">
                <p><strong>Soal {{ $question->question_number }}</strong></p>
                <input type="hidden" name="questions[{{ $index }}][question_id]" value="{{ $oldQuestion['question_id'] ?? $question->question_id }}">

                <label class="label" for="question_text_{{ $index }}">Teks soal</label>
                <textarea class="input" id="question_text_{{ $index }}" name="questions[{{ $index }}][question_text]" required>{{ old('questions.'.$index.'.question_text', $question->question_text) }}</textarea>
                @error('questions.'.$index.'.question_text')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('questions.'.$index)
                    <div class="error-text">{{ $message }}</div>
                @enderror

                @foreach (['A', 'B', 'C', 'D'] as $label)
                    @php
                        $optionText = $question->options->firstWhere('option_label', $label)?->option_text ?? '';
                    @endphp
                    <label class="label" for="option_{{ $index }}_{{ $label }}" style="margin-top: 12px;">Opsi {{ $label }}</label>
                    <input class="input" id="option_{{ $index }}_{{ $label }}" name="questions[{{ $index }}][options][{{ $label }}]" type="text" value="{{ old('questions.'.$index.'.options.'.$label, $optionText) }}" required>
                @endforeach
                @error('questions.'.$index.'.options')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('questions.'.$index.'.options.A')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('questions.'.$index.'.options.B')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('questions.'.$index.'.options.C')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('questions.'.$index.'.options.D')
                    <div class="error-text">{{ $message }}</div>
                @enderror

                <p class="label" style="margin-top: 12px;">Jawaban benar</p>
                @foreach (['A', 'B', 'C', 'D'] as $label)
                    <label style="display: inline-block; margin-right: 12px;">
                        <input type="radio" name="questions[{{ $index }}][correct_answer]" value="{{ $label }}" @checked(old('questions.'.$index.'.correct_answer', $correctLabel) === $label)>
                        {{ $label }}
                    </label>
                @endforeach
                @error('questions.'.$index.'.correct_answer')
                    <div class="error-text">{{ $message }}</div>
                @enderror

                <label class="label" for="explanation_{{ $index }}" style="margin-top: 12px;">Penjelasan</label>
                <textarea class="input" id="explanation_{{ $index }}" name="questions[{{ $index }}][explanation]" required>{{ old('questions.'.$index.'.explanation', $question->explanation) }}</textarea>
                @error('questions.'.$index.'.explanation')
                    <div class="error-text">{{ $message }}</div>
                @enderror
            </div>
        @endforeach

        <button class="button" type="submit">Simpan perubahan</button>
    </form>
@endsection
