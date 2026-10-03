@php
    use App\Enums\QuestionSetStatus;
    use App\Enums\QuestionType;

    $statusLabels = [
        'draft' => 'Draf',
        'generating' => 'Dihasilkan',
        'review' => 'Ditinjau',
        'published' => 'Terbit',
        'archived' => 'Diarsipkan',
    ];
    $statusVariants = [
        'draft' => 'neutral',
        'generating' => 'processing',
        'review' => 'info',
        'published' => 'success',
        'archived' => 'neutral',
    ];
    $statusValue = $questionSet->status->value;
    $isDraft = $questionSet->status === QuestionSetStatus::DRAFT;
    $isPublished = $questionSet->status === QuestionSetStatus::PUBLISHED;
    $createdAt = $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i');
@endphp

@extends('layouts.app')

@section('title', $questionSet->title)

@section('content')
    <div class="question-set-show-page">
        <x-ui.page-header class="question-set-show-hero">
            {{ $questionSet->title }}
            <x-slot:back>
                <div class="question-set-show-links">
                    <x-ui.button variant="tertiary" class="question-set-back" href="{{ route('question-sets.index') }}">
                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Kembali ke bank soal
                    </x-ui.button>
                    @if ($questionSet->generation)
                        <x-ui.button variant="tertiary" href="{{ route('generations.show', $questionSet->generation) }}">Lihat generasi sumber</x-ui.button>
                    @endif
                    @if ($questionSet->generationRun)
                        <x-ui.button variant="tertiary" href="{{ route('generation-runs.show', $questionSet->generationRun) }}">Lihat generasi kisi-kisi sumber</x-ui.button>
                    @endif
                </div>
            </x-slot:back>
            @if ($isDraft)
                <x-slot:supporting>Setelah terbit, soal tidak dapat diedit.</x-slot:supporting>
            @endif
            <x-slot:status>
                <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'" data-question-set-status="{{ $statusValue }}">
                    {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                </x-ui.status-badge>
            </x-slot:status>
            @if ($isDraft || $isPublished)
                <x-slot:actions>
                    @if ($isDraft)
                        <form method="POST" action="{{ route('question-sets.publish', $questionSet) }}" onsubmit="return confirm('Terbitkan soal ini? Setelah terbit, soal tidak dapat diedit.')">
                            @csrf
                            <x-ui.button type="submit">Terbitkan</x-ui.button>
                        </form>
                        <x-ui.button variant="secondary" href="{{ route('question-sets.edit', $questionSet) }}">Edit</x-ui.button>
                    @endif
                    @if ($isPublished)
                        <x-ui.button variant="secondary" href="{{ route('question-sets.download-student', $questionSet) }}">Unduh DOCX siswa</x-ui.button>
                        <x-ui.button variant="secondary" href="{{ route('question-sets.download-teacher', $questionSet) }}">Unduh DOCX guru</x-ui.button>
                    @endif
                </x-slot:actions>
            @endif
        </x-ui.page-header>

        @if ($errors->has('status') || $errors->has('questions') || $errors->has('total_question') || $errors->has('question_type'))
            <div class="question-set-show-errors" role="alert">
                @error('status')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('questions')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('total_question')
                    <div class="error-text">{{ $message }}</div>
                @enderror
                @error('question_type')
                    <div class="error-text">{{ $message }}</div>
                @enderror
            </div>
        @endif

        <dl class="question-set-show-summary" aria-label="Ringkasan set soal">
            <div>
                <dt>Jumlah soal</dt>
                <dd class="question-set-show-count">{{ $questionSet->total_question }}</dd>
            </div>
            <div>
                <dt>Dibuat</dt>
                <dd>{{ $createdAt }}</dd>
            </div>
        </dl>

        <section class="question-set-show-questions" aria-labelledby="question-set-questions-title">
            <h2 id="question-set-questions-title" class="question-set-show-section-title">Daftar soal</h2>

            @forelse ($questionSet->questions as $question)
                <article class="question-set-question">
                    <header class="question-set-question-header">
                        <h3>Soal {{ $question->question_number }}</h3>
                        <p class="question-set-question-meta">
                            {{ $question->question_type->label() }}
                            @if ($question->difficulty_level)
                                · {{ $question->difficulty_level->label() }}
                            @endif
                        </p>
                    </header>

                    <p class="question-set-question-text">{{ $question->question_text }}</p>

                    @if ($question->question_type === QuestionType::MULTIPLE_CHOICE)
                        <ul class="question-set-options">
                            @foreach ($question->options as $option)
                                <li class="question-set-option @if ($option->is_correct) is-correct @endif">
                                    <span class="question-set-option-label">{{ $option->option_label }}</span>
                                    <span class="question-set-option-text">{{ $option->option_text }}</span>
                                    @if ($option->is_correct)
                                        <span class="question-set-option-badge">Jawaban benar</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif ($question->question_type === QuestionType::TRUE_FALSE)
                        <ul class="question-set-options question-set-options--boolean">
                            @foreach ($question->options as $option)
                                <li class="question-set-option @if ($option->is_correct) is-correct @endif">
                                    <span class="question-set-option-text">{{ $option->option_text }}</span>
                                    @if ($option->is_correct)
                                        <span class="question-set-option-badge">Jawaban benar</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif ($question->question_type === QuestionType::ESSAY)
                        @if ($question->correct_answer)
                            <div class="question-set-block">
                                <p class="question-set-block-label">Contoh jawaban</p>
                                <p class="question-set-block-text">{{ $question->correct_answer }}</p>
                            </div>
                        @endif
                        @if ($question->rubric)
                            <div class="question-set-block">
                                <p class="question-set-block-label">Rubrik</p>
                                <p class="question-set-block-text">{{ $question->rubric }}</p>
                            </div>
                        @endif
                    @endif

                    @if ($question->explanation)
                        <div class="question-set-block question-set-block--explanation">
                            <p class="question-set-block-label">Penjelasan</p>
                            <p class="question-set-block-text">{{ $question->explanation }}</p>
                        </div>
                    @endif
                </article>
            @empty
                <p class="muted">Belum ada soal pada set ini.</p>
            @endforelse
        </section>
    </div>
@endsection
