@php
    $assessmentLabels = [
        'formative' => 'Formatif',
        'summative' => 'Sumatif',
        'diagnostic' => 'Diagnostik',
    ];
    $difficultyLabels = [
        'easy' => 'Mudah',
        'medium' => 'Sedang',
        'hard' => 'Sulit',
        'hots' => 'HOTS',
    ];
@endphp

@extends('layouts.app')

@section('title', 'Generate soal')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke materi</a>
    </div>

    <p class="muted">GENERATE QUESTIONS</p>
    <h1>Generate soal</h1>
    <p><strong>Materi:</strong> {{ $material->title }}</p>

    @include('generations._quota', ['usage' => $usage])

    <div class="card">
        <form method="POST" action="{{ route('generations.store', $material) }}">
            @csrf

            <div class="field-grid">
                <div>
                    <label class="label" for="assessment_type">Tipe assessment</label>
                    <select class="input" id="assessment_type" name="assessment_type" required>
                        @foreach ($assessments as $assessment)
                            <option value="{{ $assessment->value }}" @selected(old('assessment_type', 'formative') === $assessment->value)>
                                {{ $assessmentLabels[$assessment->value] ?? $assessment->value }}
                            </option>
                        @endforeach
                    </select>
                    @error('assessment_type')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="label" for="difficulty_level">Tingkat kesulitan</label>
                    <select class="input" id="difficulty_level" name="difficulty_level" required>
                        @foreach ($difficulties as $difficulty)
                            <option value="{{ $difficulty->value }}" @selected(old('difficulty_level', 'medium') === $difficulty->value)>
                                {{ $difficultyLabels[$difficulty->value] ?? $difficulty->value }}
                            </option>
                        @endforeach
                    </select>
                    @error('difficulty_level')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="label" for="question_type">Tipe soal</label>
                    <input class="input" id="question_type" type="text" value="Pilihan ganda" disabled>
                    <input type="hidden" name="question_type" value="multiple_choice">
                    @error('question_type')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="label" for="question_count">Jumlah soal</label>
                    <input class="input" id="question_count" name="question_count" type="number" min="1" max="{{ $maxQuestions }}" value="{{ old('question_count', 5) }}" required>
                    @error('question_count')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="label" for="output_language">Bahasa keluaran</label>
                    <select class="input" id="output_language" name="output_language" required>
                        <option value="id" @selected(old('output_language', 'id') === 'id')>Bahasa Indonesia</option>
                        <option value="en" @selected(old('output_language', 'id') === 'en')>English</option>
                    </select>
                    @error('output_language')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            @error('quota')
                <div class="error-text">{{ $message }}</div>
            @enderror

            @error('material')
                <div class="error-text">{{ $message }}</div>
            @enderror

            <button class="button" style="margin-top: 16px;" type="submit">Mulai generate</button>
        </form>
    </div>
@endsection
