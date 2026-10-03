@php
    use App\Enums\QuestionType;

    $questionsInput = old('questions');
@endphp

@extends('layouts.app')

@section('title', 'Edit soal')

@section('content')
    <div class="question-set-edit-page">
        <x-ui.page-header>
            Edit soal
            <x-slot:back>
                <x-ui.button variant="tertiary" class="question-set-back" href="{{ route('question-sets.show', $questionSet) }}">
                    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    Kembali ke detail
                </x-ui.button>
            </x-slot:back>
        </x-ui.page-header>

        @if ($errors->has('questions') || $errors->has('status'))
            <div class="question-set-edit-errors" role="alert">
                @error('questions')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('status')
                    <div class="error-text">{{ $message }}</div>
                @enderror
            </div>
        @endif

        <form class="question-set-edit-form" method="POST" action="{{ route('question-sets.update', $questionSet) }}">
            @csrf
            @method('PATCH')

            <x-ui.panel class="question-set-edit-panel">
                <div class="question-set-edit-field">
                    <label class="label" for="title">Judul</label>
                    <input class="ui-input" id="title" name="title" type="text" value="{{ old('title', $questionSet->title) }}" required maxlength="255" @error('title') aria-invalid="true" aria-describedby="title-error" @enderror>
                    @error('title')
                        <div class="error-text" id="title-error">{{ $message }}</div>
                    @enderror
                </div>
            </x-ui.panel>

            @foreach ($questionSet->questions as $index => $question)
                @php
                    $oldQuestion = is_array($questionsInput) ? ($questionsInput[$index] ?? []) : [];
                @endphp
                <x-ui.panel class="question-set-edit-panel question-set-edit-question" role="group" aria-labelledby="question-heading-{{ $index }}">
                    <div class="question-set-edit-question-header">
                        <h2 id="question-heading-{{ $index }}">Soal {{ $question->question_number }}</h2>
                        <p class="question-set-question-meta">{{ $question->question_type->label() }}</p>
                    </div>
                    <input type="hidden" name="questions[{{ $index }}][question_id]" value="{{ $oldQuestion['question_id'] ?? $question->question_id }}">

                    @error('questions.'.$index)
                        <div class="error-text">{{ $message }}</div>
                    @enderror

                    <div class="question-set-edit-field">
                        <label class="label" for="question_text_{{ $index }}">Teks soal</label>
                        <textarea class="ui-input" id="question_text_{{ $index }}" name="questions[{{ $index }}][question_text]" required @error('questions.'.$index.'.question_text') aria-invalid="true" aria-describedby="question_text_{{ $index }}_error" @enderror>{{ old('questions.'.$index.'.question_text', $question->question_text) }}</textarea>
                        @error('questions.'.$index.'.question_text')
                            <div class="error-text" id="question_text_{{ $index }}_error">{{ $message }}</div>
                        @enderror
                    </div>

                    @if ($question->question_type === QuestionType::MULTIPLE_CHOICE)
                        @php
                            $correctLabel = $question->options->firstWhere('is_correct', true)?->option_label ?? 'A';
                        @endphp
                        <div class="question-set-edit-options">
                            @foreach (['A', 'B', 'C', 'D'] as $label)
                                @php
                                    $optionText = $question->options->firstWhere('option_label', $label)?->option_text ?? '';
                                @endphp
                                <div class="question-set-edit-field">
                                    <label class="label" for="option_{{ $index }}_{{ $label }}">Opsi {{ $label }}</label>
                                    <input class="ui-input" id="option_{{ $index }}_{{ $label }}" name="questions[{{ $index }}][options][{{ $label }}]" type="text" value="{{ old('questions.'.$index.'.options.'.$label, $optionText) }}" required @error('questions.'.$index.'.options.'.$label) aria-invalid="true" aria-describedby="option_{{ $index }}_{{ $label }}_error" @enderror>
                                    @error('questions.'.$index.'.options.'.$label)
                                        <div class="error-text" id="option_{{ $index }}_{{ $label }}_error">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endforeach
                            @error('questions.'.$index.'.options')
                                <div class="error-text">{{ $message }}</div>
                            @enderror
                        </div>

                        <fieldset class="question-set-edit-choices" @error('questions.'.$index.'.correct_answer') aria-describedby="correct_{{ $index }}_error" @enderror>
                            <legend class="label">Jawaban benar</legend>
                            <div class="question-set-edit-choice-list">
                                @foreach (['A', 'B', 'C', 'D'] as $label)
                                    <label class="question-set-choice">
                                        <input type="radio" name="questions[{{ $index }}][correct_answer]" value="{{ $label }}" @checked(old('questions.'.$index.'.correct_answer', $correctLabel) === $label)>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('questions.'.$index.'.correct_answer')
                                <div class="error-text" id="correct_{{ $index }}_error">{{ $message }}</div>
                            @enderror
                        </fieldset>
                    @elseif ($question->question_type === QuestionType::TRUE_FALSE)
                        @php
                            $correctIsTrue = $question->options->firstWhere('is_correct', true)?->option_label === 'TRUE';
                            $correctValue = $correctIsTrue ? 'Benar' : 'Salah';
                        @endphp
                        <fieldset class="question-set-edit-choices" @error('questions.'.$index.'.correct_answer') aria-describedby="correct_{{ $index }}_error" @enderror>
                            <legend class="label">Jawaban benar</legend>
                            <div class="question-set-edit-choice-list question-set-edit-choice-list--boolean">
                                <label class="question-set-choice">
                                    <input type="radio" name="questions[{{ $index }}][correct_answer]" value="Benar" @checked(old('questions.'.$index.'.correct_answer', $correctValue) === 'Benar')>
                                    <span>Benar</span>
                                </label>
                                <label class="question-set-choice">
                                    <input type="radio" name="questions[{{ $index }}][correct_answer]" value="Salah" @checked(old('questions.'.$index.'.correct_answer', $correctValue) === 'Salah')>
                                    <span>Salah</span>
                                </label>
                            </div>
                            @error('questions.'.$index.'.correct_answer')
                                <div class="error-text" id="correct_{{ $index }}_error">{{ $message }}</div>
                            @enderror
                        </fieldset>
                    @elseif ($question->question_type === QuestionType::ESSAY)
                        <div class="question-set-edit-field">
                            <label class="label" for="model_answer_{{ $index }}">Contoh jawaban</label>
                            <textarea class="ui-input" id="model_answer_{{ $index }}" name="questions[{{ $index }}][model_answer]" required @error('questions.'.$index.'.model_answer') aria-invalid="true" aria-describedby="model_answer_{{ $index }}_error" @enderror>{{ old('questions.'.$index.'.model_answer', $question->correct_answer) }}</textarea>
                            @error('questions.'.$index.'.model_answer')
                                <div class="error-text" id="model_answer_{{ $index }}_error">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="question-set-edit-field">
                            <label class="label" for="rubric_{{ $index }}">Rubrik</label>
                            <textarea class="ui-input" id="rubric_{{ $index }}" name="questions[{{ $index }}][rubric]" required @error('questions.'.$index.'.rubric') aria-invalid="true" aria-describedby="rubric_{{ $index }}_error" @enderror>{{ old('questions.'.$index.'.rubric', $question->rubric) }}</textarea>
                            @error('questions.'.$index.'.rubric')
                                <div class="error-text" id="rubric_{{ $index }}_error">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif

                    <div class="question-set-edit-field">
                        <label class="label" for="explanation_{{ $index }}">Penjelasan</label>
                        <textarea class="ui-input" id="explanation_{{ $index }}" name="questions[{{ $index }}][explanation]" required @error('questions.'.$index.'.explanation') aria-invalid="true" aria-describedby="explanation_{{ $index }}_error" @enderror>{{ old('questions.'.$index.'.explanation', $question->explanation) }}</textarea>
                        @error('questions.'.$index.'.explanation')
                            <div class="error-text" id="explanation_{{ $index }}_error">{{ $message }}</div>
                        @enderror
                    </div>
                </x-ui.panel>
            @endforeach

            <div class="question-set-edit-actions">
                <x-ui.button type="submit">Simpan</x-ui.button>
            </div>
        </form>
    </div>
@endsection
