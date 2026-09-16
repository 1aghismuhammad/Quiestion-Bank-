@php
    use App\Enums\QuestionSetStatus;
    use App\Enums\QuestionType;

    $statusLabels = [
        'draft' => 'Draf',
        'generating' => 'Menghasilkan',
        'review' => 'Tinjauan',
        'published' => 'Terbit',
        'archived' => 'Arsip',
    ];
    $isDraft = $questionSet->status === QuestionSetStatus::DRAFT;
    $isPublished = $questionSet->status === QuestionSetStatus::PUBLISHED;
@endphp

@extends('layouts.app')

@section('title', $questionSet->title)

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('question-sets.index') }}">Kembali ke Question Bank</a>
        @if ($questionSet->generation)
            <a href="{{ route('generations.show', $questionSet->generation) }}">Lihat generasi sumber</a>
        @endif
        @if ($questionSet->generationRun)
            <a href="{{ route('generation-runs.show', $questionSet->generationRun) }}">Lihat generasi kisi-kisi sumber</a>
        @endif
        @if ($isDraft)
            <a class="button" href="{{ route('question-sets.edit', $questionSet) }}">Edit</a>
            <form method="POST" action="{{ route('question-sets.publish', $questionSet) }}" onsubmit="return confirm('Terbitkan soal ini? Setelah terbit, soal tidak dapat diedit.')">
                @csrf
                <button class="button" type="submit">Terbitkan</button>
            </form>
        @endif
        @if ($isPublished)
            <a class="button" href="{{ route('question-sets.download-student', $questionSet) }}">Unduh DOCX Siswa</a>
            <a class="button" href="{{ route('question-sets.download-teacher', $questionSet) }}">Unduh DOCX Guru</a>
        @endif
    </div>

    <p class="muted">QUESTION BANK</p>
    <h1>{{ $questionSet->title }}</h1>

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

    <div class="card" style="margin-bottom: 20px;">
        <p>
            <strong>Status:</strong>
            <span class="status">{{ $statusLabels[$questionSet->status->value] ?? $questionSet->status->value }}</span>
            <span class="muted">({{ $questionSet->status->value }})</span>
        </p>
        <p><strong>Jumlah soal:</strong> {{ $questionSet->total_question }}</p>
        <p class="muted">Dibuat {{ $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
    </div>

    @foreach ($questionSet->questions as $question)
        <div class="card" style="margin-bottom: 16px;">
            <p>
                <strong>{{ $question->question_number }}.</strong> {{ $question->question_text }}
            </p>
            <p class="muted">
                {{ $question->question_type->label() }}
                @if ($question->difficulty_level)
                    · {{ $question->difficulty_level->label() }}
                @endif
            </p>

            @if ($question->question_type === QuestionType::MULTIPLE_CHOICE)
                @foreach ($question->options as $option)
                    <p>
                        <strong>{{ $option->option_label }}.</strong>
                        {{ $option->option_text }}
                        @if ($option->is_correct)
                            <span class="muted">(Jawaban benar)</span>
                        @endif
                    </p>
                @endforeach
            @elseif ($question->question_type === QuestionType::TRUE_FALSE)
                @foreach ($question->options as $option)
                    <p>
                        {{ $option->option_text }}
                        @if ($option->is_correct)
                            <span class="muted">(Jawaban benar)</span>
                        @endif
                    </p>
                @endforeach
            @elseif ($question->question_type === QuestionType::ESSAY)
                @if ($question->correct_answer)
                    <p><strong>Contoh jawaban:</strong> {{ $question->correct_answer }}</p>
                @endif
                @if ($question->rubric)
                    <p><strong>Rubrik:</strong> {{ $question->rubric }}</p>
                @endif
            @endif

            @if ($question->explanation)
                <p><strong>Penjelasan:</strong> {{ $question->explanation }}</p>
            @endif
        </div>
    @endforeach
@endsection
