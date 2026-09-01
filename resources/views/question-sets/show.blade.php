@php
    $statusLabels = [
        'draft' => 'Draf',
        'generating' => 'Menghasilkan',
        'review' => 'Tinjauan',
        'published' => 'Terbit',
        'archived' => 'Arsip',
    ];
@endphp

@extends('layouts.app')

@section('title', $questionSet->title)

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('question-sets.index') }}">Kembali ke Question Bank</a>
        @if ($questionSet->generation)
            <a href="{{ route('generations.show', $questionSet->generation) }}">Lihat generasi sumber</a>
        @endif
    </div>

    <p class="muted">QUESTION BANK</p>
    <h1>{{ $questionSet->title }}</h1>

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
            <p><strong>{{ $question->question_number }}.</strong> {{ $question->question_text }}</p>
            @foreach ($question->options as $option)
                <p>
                    <strong>{{ $option->option_label }}.</strong>
                    {{ $option->option_text }}
                    @if ($option->is_correct)
                        <span class="muted">(Jawaban benar)</span>
                    @endif
                </p>
            @endforeach
            <p><strong>Penjelasan:</strong> {{ $question->explanation }}</p>
        </div>
    @endforeach
@endsection
