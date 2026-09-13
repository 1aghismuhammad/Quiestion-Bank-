@extends('layouts.app')

@section('title', 'Generate dari kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.blueprints.show', [$material, $blueprint]) }}">Kembali ke kisi-kisi</a>
    </div>

    <p class="muted">GENERATION RUN</p>
    <h1>Generate soal dari kisi-kisi</h1>
    <p><strong>Kisi-kisi:</strong> {{ $blueprint->title }}</p>
    @if ($isAdvanced)
        <p class="muted">Mode lanjutan · {{ $rowCount }} kelompok · {{ $totalQuestions }} soal · Pilihan ganda</p>
    @else
        <p class="muted">Jumlah soal: {{ $totalQuestions }} · Mode sederhana · Pilihan ganda</p>
    @endif

    @include('generations._quota', ['usage' => $usage])

    @if (! $canStart)
        <div class="alert alert-error">Paket Pro aktif diperlukan untuk memulai generasi dari kisi-kisi lanjutan.</div>
    @else
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

                @if ($isAdvanced)
                    <label style="display: block; margin-top: 16px;">
                        <input type="checkbox" name="shuffle_questions" value="1" @checked((bool) old('shuffle_questions'))>
                        Acak urutan soal
                    </label>
                    <label style="display: block; margin-top: 8px;">
                        <input type="checkbox" name="shuffle_options" value="1" @checked((bool) old('shuffle_options'))>
                        Acak urutan opsi
                    </label>
                    @if ($totalQuestions <= 10)
                        <p class="muted" style="margin-top: 12px;">Mode lanjutan dengan 1–10 soal membutuhkan tingkat kesulitan berbeda, atau salah satu pengacakan di atas.</p>
                    @endif
                @endif

                <p style="margin-top: 16px;"><strong>Kredit yang diperlukan:</strong> {{ $creditsRequired }}</p>

                <div style="margin-top: 16px;">
                    <button class="button" type="submit">Mulai generasi</button>
                </div>
            </form>
        </div>
    @endif
@endsection
