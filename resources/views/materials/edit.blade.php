@extends('layouts.app')

@section('title', 'Edit materi')

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route('materials.show', $material) }}">Kembali ke detail</a>
    </div>

    <p class="muted">EDIT MATERI</p>
    <h1>{{ $material->title }}</h1>

    <div class="card" style="max-width: 720px;">
        <form method="POST" action="{{ route('materials.update', $material) }}">
            @csrf
            @method('PATCH')

            <label class="label" for="title">Judul</label>
            <input class="input" id="title" name="title" type="text" value="{{ old('title', $material->title) }}" required>
            @error('title')
                <div class="error-text">{{ $message }}</div>
            @enderror

            @if ($isText)
                <label class="label" for="content" style="margin-top: 16px;">Konten</label>
                <textarea class="input" id="content" name="content" required>{{ old('content', $material->content) }}</textarea>
                @error('content')
                    <div class="error-text">{{ $message }}</div>
                @enderror
            @endif

            <button class="button" style="margin-top: 16px;" type="submit">Simpan perubahan</button>
        </form>
    </div>
@endsection
