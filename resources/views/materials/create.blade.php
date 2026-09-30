@extends('layouts.app')

@section('title', 'Unggah materi')

@section('content')
    <div class="page-form">
        <x-ui.page-header>
            Unggah materi
            <x-slot:supporting>
                Unggah file PDF, DOCX, atau TXT (maksimal 10 MB). Materi teks lama tetap dapat dilihat dan diedit, tetapi materi baru hanya dapat dibuat melalui unggah file.
            </x-slot:supporting>
        </x-ui.page-header>

        <form method="POST" action="{{ route('materials.store-upload') }}" enctype="multipart/form-data">
            @csrf

            <x-ui.text-input
                name="title"
                id="title"
                label="Judul"
                :value="old('title')"
                required
            />

            <div>
                <label class="label" for="file">Berkas PDF, DOCX, atau TXT</label>
                <input class="ui-input" id="file" name="file" type="file" accept=".pdf,.docx,.txt" required>
                @error('file')
                    <div class="error-text" id="file-error">{{ $message }}</div>
                @enderror
            </div>

            <div class="action-stack">
                <x-ui.button type="submit">Unggah materi</x-ui.button>
                <x-ui.button variant="tertiary" href="{{ route('materials.index') }}">Kembali</x-ui.button>
            </div>
        </form>
    </div>
@endsection
