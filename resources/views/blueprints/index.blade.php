@extends('layouts.app')

@section('title', 'Kisi-kisi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke materi</a>
    </div>

    <p class="muted">KISI-KISI</p>
    <h1>{{ $material->title }}</h1>

    <div class="actions" style="margin-bottom: 20px;">
        <a class="button" href="{{ route('materials.blueprints.create', $material) }}">Buat kisi-kisi manual</a>
        <form method="POST" action="{{ route('materials.blueprints.ai', $material) }}">
            @csrf
            <button class="button button-secondary" type="submit">Isi dengan AI</button>
        </form>
    </div>

    @if ($readyProfile === null)
        <div class="alert alert-error">
            Profil materi yang siap diperlukan sebelum kisi-kisi dapat dibuat atau dikonfirmasi.
            <a href="{{ route('materials.profile.show', $material) }}">Buka analisis profil</a>
        </div>
    @endif

    <div class="card">
        @if ($blueprints->isEmpty())
            <p class="muted">Belum ada kisi-kisi untuk materi ini.</p>
        @else
            <table class="table">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Sumber</th>
                        <th>Baris</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blueprints as $blueprint)
                        <tr>
                            <td>
                                <a href="{{ route('materials.blueprints.show', [$material, $blueprint]) }}">{{ $blueprint->title }}</a>
                            </td>
                            <td>{{ $blueprint->lifecycle_status->value }}</td>
                            <td>{{ $blueprint->source->value }}</td>
                            <td>{{ $blueprint->rows->count() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
