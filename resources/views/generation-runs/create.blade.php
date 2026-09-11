@extends('layouts.app')

@section('title', 'Generate dari kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.blueprints.show', [$material, $blueprint]) }}">Kembali ke kisi-kisi</a>
    </div>

    <p class="muted">GENERATION RUN</p>
    <h1>Generate soal dari kisi-kisi</h1>
    <p><strong>Kisi-kisi:</strong> {{ $blueprint->title }}</p>
    <p class="muted">Jumlah soal: {{ $totalQuestions }} · Mode sederhana · Pilihan ganda</p>

    @include('generations._quota', ['usage' => $usage])

    <div class="card">
        <form method="POST" action="{{ route('generation-runs.store', [$material, $blueprint]) }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">

            <label class="label" for="output_language">Bahasa keluaran</label>
            <select class="input" id="output_language" name="output_language" required>
                @foreach ($languages as $language)
                    <option value="{{ $language->value }}" @selected(old('output_language', 'id') === $language->value)>{{ $language->ownerLabel() }}</option>
                @endforeach
            </select>
            @error('output_language')
                <div class="error-text">{{ $message }}</div>
            @enderror

            <p style="margin-top: 16px;"><strong>Kredit yang diperlukan:</strong> {{ $creditsRequired }}</p>

            <div style="margin-top: 16px;">
                <button class="button" type="submit">Mulai generasi</button>
            </div>
        </form>
    </div>
@endsection
