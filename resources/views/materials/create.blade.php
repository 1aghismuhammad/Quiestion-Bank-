@extends('layouts.app')

@section('title', 'Buat materi')

@section('content')
    <p class="muted">MATERIAL MANAGEMENT</p>
    <h1>Buat materi</h1>
    <p class="muted">Pilih teks manual atau unggah PDF, DOCX, atau TXT (maksimal 10 MB).</p>

    <div class="grid">
        <div class="card">
            <h2>Materi teks</h2>
            <form method="POST" action="{{ route('materials.store-text') }}">
                @csrf

                <label class="label" for="text_title">Judul</label>
                <input class="input" id="text_title" name="title" type="text" value="{{ old('title') }}" required>
                @error('title')
                    <div class="error-text">{{ $message }}</div>
                @enderror

                <label class="label" for="content" style="margin-top: 16px;">Konten</label>
                <textarea class="input" id="content" name="content" required>{{ old('content') }}</textarea>
                @error('content')
                    <div class="error-text">{{ $message }}</div>
                @enderror

                <button class="button" style="margin-top: 16px;" type="submit">Simpan materi teks</button>
            </form>
        </div>

        <div class="card">
            <h2>Unggah file</h2>
            <form method="POST" action="{{ route('materials.store-upload') }}" enctype="multipart/form-data">
                @csrf

                <label class="label" for="upload_title">Judul</label>
                <input class="input" id="upload_title" name="title" type="text" value="{{ old('title') }}" required>

                <label class="label" for="file" style="margin-top: 16px;">File (PDF, DOCX, TXT)</label>
                <input class="input" id="file" name="file" type="file" accept=".pdf,.docx,.txt" required>
                @error('file')
                    <div class="error-text">{{ $message }}</div>
                @enderror

                <button class="button" style="margin-top: 16px;" type="submit">Unggah materi</button>
            </form>
        </div>
    </div>
@endsection
