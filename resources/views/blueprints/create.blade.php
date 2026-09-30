@extends('layouts.app')

@section('title', 'Buat kisi-kisi manual')

@section('content')
    <div class="page-form">
        <x-ui.page-header>
            Buat kisi-kisi manual
            <x-slot:back>
                <x-ui.button variant="tertiary" href="{{ route('materials.blueprints.index', $material) }}">Kembali ke kisi-kisi</x-ui.button>
            </x-slot:back>
        </x-ui.page-header>

        <form method="POST" action="{{ route('materials.blueprints.store', $material) }}">
            @csrf
            @include('blueprints._form', [
                'blueprint' => null,
                'assessments' => $assessments,
                'cognitiveLevels' => $cognitiveLevels,
                'difficulties' => $difficulties,
                'maxRows' => $maxRows,
                'mappingOptions' => $mappingOptions,
                'isPro' => $isPro,
                'questionTypes' => $questionTypes ?? \App\Enums\QuestionType::cases(),
            ])
            <x-ui.button type="submit">Simpan draf</x-ui.button>
        </form>
    </div>
@endsection
