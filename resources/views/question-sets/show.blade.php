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
@endphp

@extends('layouts.app')

@section('title', $questionSet->title)

@section('content')
    <x-ui.page-header>
        {{ $questionSet->title }}
        <x-slot:back>
            <x-ui.button variant="tertiary" href="{{ route('question-sets.index') }}">Kembali ke bank soal</x-ui.button>
            @if ($questionSet->generation)
                <x-ui.button variant="tertiary" href="{{ route('generations.show', $questionSet->generation) }}">Lihat generasi sumber</x-ui.button>
            @endif
            @if ($questionSet->generationRun)
                <x-ui.button variant="tertiary" href="{{ route('generation-runs.show', $questionSet->generationRun) }}">Lihat generasi kisi-kisi sumber</x-ui.button>
            @endif
        </x-slot:back>
        <x-slot:status>
            <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'" data-question-set-status="{{ $statusValue }}">
                {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
            </x-ui.status-badge>
        </x-slot:status>
    </x-ui.page-header>

    <div class="action-stack" style="margin-bottom: 20px;">
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
    </div>

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

    <x-ui.panel>
        <p><strong>Jumlah soal:</strong> {{ $questionSet->total_question }}</p>
        <p class="muted">Dibuat {{ $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
    </x-ui.panel>

    @forelse ($questionSet->questions as $question)
        <x-ui.panel>
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
        </x-ui.panel>
    @empty
        <p>Belum ada soal pada set ini.</p>
    @endforelse
@endsection
