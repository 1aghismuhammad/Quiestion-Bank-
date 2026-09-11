@extends('layouts.app')

@section('title', 'Buat kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.blueprints.index', $material) }}">Kembali ke kisi-kisi</a>
    </div>

    <p class="muted">KISI-KISI MANUAL</p>
    <h1>Buat kisi-kisi</h1>

    <div class="card">
        <form method="POST" action="{{ route('materials.blueprints.store', $material) }}">
            @csrf
            @include('blueprints._form', [
                'blueprint' => null,
                'assessments' => $assessments,
                'cognitiveLevels' => $cognitiveLevels,
                'difficulties' => $difficulties,
                'maxRows' => $maxRows,
                'mappingOptions' => $mappingOptions,
            ])
            <button class="button" type="submit">Simpan draf</button>
        </form>
    </div>
@endsection
