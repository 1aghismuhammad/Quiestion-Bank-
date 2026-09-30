@extends('layouts.app')

@section('title', 'Buat soal dari kisi-kisi ini')

@section('content')
    <div class="page-form">
        <x-ui.page-header>
            Buat soal dari kisi-kisi ini
            <x-slot:back>
                <x-ui.button variant="tertiary" href="{{ route('materials.blueprints.show', [$material, $blueprint]) }}">Kembali ke kisi-kisi</x-ui.button>
            </x-slot:back>
            <x-slot:supporting>{{ $blueprint->title }}</x-slot:supporting>
        </x-ui.page-header>

        @if ($isAdvanced)
            <p class="muted">Mode lanjutan · {{ $rowCount }} kelompok · {{ $totalQuestions }} soal · {{ $typeSummary }}</p>
        @else
            <p class="muted">Jumlah soal: {{ $totalQuestions }} · Mode sederhana · {{ $typeSummary }}</p>
        @endif

        @include('generations._quota', ['usage' => $usage])

        @if (! $canStart)
            <x-ui.alert variant="danger">Paket Pro aktif diperlukan untuk memulai generasi dari kisi-kisi lanjutan.</x-ui.alert>
        @else
            <form method="POST" action="{{ route('generation-runs.store', [$material, $blueprint]) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">

                <label class="label" for="output_language">Bahasa keluaran</label>
                <select class="ui-input" id="output_language" name="output_language" required>
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
                        Acak urutan opsi (pilihan ganda saja)
                    </label>
                    @if ($totalQuestions <= 10)
                        <p class="muted" style="margin-top: 12px;">Mode lanjutan dengan 1–10 soal membutuhkan tingkat kesulitan berbeda, tipe soal berbeda, atau salah satu pengacakan di atas.</p>
                    @endif
                @endif

                <p style="margin-top: 16px;"><strong>Kredit yang diperlukan:</strong> {{ $creditsRequired }}</p>

                <x-ui.button type="submit">Buat soal</x-ui.button>
            </form>
        @endif
    </div>
@endsection
