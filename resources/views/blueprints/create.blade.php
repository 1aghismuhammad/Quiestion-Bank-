@extends('layouts.app')

@section('title', 'Buat kisi-kisi manual')

@section('content')
    <div class="blueprint-create-page">
        <x-ui.page-header>
            Buat kisi-kisi manual
            <x-slot:back>
                <a class="blueprint-create-back" href="{{ route('materials.blueprints.index', $material) }}">
                    <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    <span>Kembali ke kisi-kisi</span>
                </a>
            </x-slot:back>
            <x-slot:supporting>
                Susun struktur soal berdasarkan tujuan, materi, dan indikator dari profil materi.
            </x-slot:supporting>
        </x-ui.page-header>

        <form method="POST" action="{{ route('materials.blueprints.store', $material) }}">
            @csrf
            <h2 class="blueprint-create-settings-title">Pengaturan kisi-kisi</h2>
            @include('blueprints._form', [
                'blueprint' => null,
                'assessments' => $assessments,
                'cognitiveLevels' => $cognitiveLevels,
                'difficulties' => $difficulties,
                'maxRows' => $maxRows,
                'mappingOptions' => $mappingOptions,
                'isPro' => $isPro,
                'questionTypes' => $questionTypes ?? \App\Enums\QuestionType::cases(),
                'createLayout' => true,
            ])
            <button class="blueprint-create-save" type="submit">Simpan draf</button>
        </form>
    </div>
@endsection
