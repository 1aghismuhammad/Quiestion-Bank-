@php
    use App\Enums\QuestionType;

    $questionsInput = old('questions');
@endphp

@extends('layouts.app')

@section('title', 'Edit soal')

@section('content')
    <div class="page-form">
        <x-ui.page-header>
            Edit soal
            <x-slot:back>
                <x-ui.button variant="tertiary" href="{{ route('question-sets.show', $questionSet) }}">Kembali ke detail</x-ui.button>
            </x-slot:back>
        </x-ui.page-header>

        @error('questions')
            <div class="error-text">{{ $message }}</div>
        @enderror
        @error('status')
            <div class="error-text">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('question-sets.update', $questionSet) }}">
            @csrf
            @method('PATCH')

            <div>
                <label class="label" for="title">Judul</label>
                <input class="ui-input" id="title" name="title" type="text" value="{{ old('title', $questionSet->title) }}" required maxlength="255">
                @error('title')
                    <div class="error-text">{{ $message }}</div>
                @enderror
            </div>

            @foreach ($questionSet->questions as $index => $question)
                @php
                    $oldQuestion = is_array($questionsInput) ? ($questionsInput[$index] ?? []) : [];
                @endphp
                <x-ui.panel>
                    <p>
                        <strong>Soal {{ $question->question_number }}</strong>
                        <span class="muted">· {{ $question->question_type->label() }}</span>
                    </p>
                    <input type="hidden" name="questions[{{ $index }}][question_id]" value="{{ $oldQuestion['question_id'] ?? $question->question_id }}">

                    <label class="label" for="question_text_{{ $index }}">Teks soal</label>
                    <textarea class="ui-input" id="question_text_{{ $index }}" name="questions[{{ $index }}][question_text]" required>{{ old('questions.'.$index.'.question_text', $question->question_text) }}</textarea>
                    @error('questions.'.$index.'.question_text')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                    @error('questions.'.$index)
                        <div class="error-text">{{ $message }}</div>
                    @enderror

                    @if ($question->question_type === QuestionType::MULTIPLE_CHOICE)
                        @php
                            $correctLabel = $question->options->firstWhere('is_correct', true)?->option_label ?? 'A';
                        @endphp
                        @foreach (['A', 'B', 'C', 'D'] as $label)
                            @php
                                $optionText = $question->options->firstWhere('option_label', $label)?->option_text ?? '';
                            @endphp
                            <label class="label" for="option_{{ $index }}_{{ $label }}">Opsi {{ $label }}</label>
                            <input class="ui-input" id="option_{{ $index }}_{{ $label }}" name="questions[{{ $index }}][options][{{ $label }}]" type="text" value="{{ old('questions.'.$index.'.options.'.$label, $optionText) }}" required>
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

                        <fieldset>
                            <legend class="label">Jawaban benar</legend>
                            @foreach (['A', 'B', 'C', 'D'] as $label)
                                <label style="display: inline-block; margin-right: 12px;">
                                    <input type="radio" name="questions[{{ $index }}][correct_answer]" value="{{ $label }}" @checked(old('questions.'.$index.'.correct_answer', $correctLabel) === $label)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </fieldset>
                        @error('questions.'.$index.'.correct_answer')
                            <div class="error-text">{{ $message }}</div>
                        @enderror
                    @elseif ($question->question_type === QuestionType::TRUE_FALSE)
                        @php
                            $correctIsTrue = $question->options->firstWhere('is_correct', true)?->option_label === 'TRUE';
                            $correctValue = $correctIsTrue ? 'Benar' : 'Salah';
                        @endphp
                        <fieldset>
                            <legend class="label">Jawaban benar</legend>
                            <label style="display: inline-block; margin-right: 12px;">
                                <input type="radio" name="questions[{{ $index }}][correct_answer]" value="Benar" @checked(old('questions.'.$index.'.correct_answer', $correctValue) === 'Benar')>
                                Benar
                            </label>
                            <label style="display: inline-block; margin-right: 12px;">
                                <input type="radio" name="questions[{{ $index }}][correct_answer]" value="Salah" @checked(old('questions.'.$index.'.correct_answer', $correctValue) === 'Salah')>
                                Salah
                            </label>
                        </fieldset>
                        @error('questions.'.$index.'.correct_answer')
                            <div class="error-text">{{ $message }}</div>
                        @enderror
                    @elseif ($question->question_type === QuestionType::ESSAY)
                        <label class="label" for="model_answer_{{ $index }}">Contoh jawaban</label>
                        <textarea class="ui-input" id="model_answer_{{ $index }}" name="questions[{{ $index }}][model_answer]" required>{{ old('questions.'.$index.'.model_answer', $question->correct_answer) }}</textarea>
                        @error('questions.'.$index.'.model_answer')
                            <div class="error-text">{{ $message }}</div>
                        @enderror

                        <label class="label" for="rubric_{{ $index }}">Rubrik</label>
                        <textarea class="ui-input" id="rubric_{{ $index }}" name="questions[{{ $index }}][rubric]" required>{{ old('questions.'.$index.'.rubric', $question->rubric) }}</textarea>
                        @error('questions.'.$index.'.rubric')
                            <div class="error-text">{{ $message }}</div>
                        @enderror
                    @endif

                    <label class="label" for="explanation_{{ $index }}">Penjelasan</label>
                    <textarea class="ui-input" id="explanation_{{ $index }}" name="questions[{{ $index }}][explanation]" required>{{ old('questions.'.$index.'.explanation', $question->explanation) }}</textarea>
                    @error('questions.'.$index.'.explanation')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                </x-ui.panel>
            @endforeach

            <x-ui.button type="submit">Simpan</x-ui.button>
        </form>
    </div>
@endsection
