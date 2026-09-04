@extends('layouts.app')

@section('title', 'Buat materi')

@section('content')
    <p class="muted">MATERIAL MANAGEMENT</p>
    <h1>Buat materi</h1>
    <p class="muted">Unggah file PDF, DOCX, atau TXT (maksimal 10 MB). Materi teks lama tetap dapat dilihat dan diedit, tetapi materi baru hanya dapat dibuat melalui unggah file.</p>

    <div class="card">
        <h2>Unggah file</h2>
        <form method="POST" action="{{ route('materials.store-upload') }}" enctype="multipart/form-data">
            @csrf

            <label class="label" for="title">Judul</label>
            <input class="input" id="title" name="title" type="text" value="{{ old('title') }}" required>
            @error('title')
                <div class="error-text">{{ $message }}</div>
            @enderror

            <label class="label" for="file" style="margin-top: 16px;">File (PDF, DOCX, TXT)</label>
            <input class="input" id="file" name="file" type="file" accept=".pdf,.docx,.txt" required>
            @error('file')
                <div class="error-text">{{ $message }}</div>
            @enderror

            <button class="button" style="margin-top: 16px;" type="submit">Unggah materi</button>
        </form>
    </div>
@endsection
