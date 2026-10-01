@extends('layouts.app')

@section('title', 'Unggah materi')

@section('content')
    <div class="material-upload-page">
        <x-ui.page-header class="material-upload-header">
            <x-slot:back>
                <a class="material-upload-back" href="{{ route('materials.index') }}">
                    <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    <span>Kembali ke materi</span>
                </a>
            </x-slot:back>
            Unggah materi
            <x-slot:supporting>
                Unggah berkas materi yang akan digunakan sebagai sumber pembuatan soal.
            </x-slot:supporting>
        </x-ui.page-header>

        <form class="material-upload-panel" method="POST" action="{{ route('materials.store-upload') }}" enctype="multipart/form-data">
            @csrf

            <div class="material-upload-field">
                <label class="label" for="file">Berkas PDF, DOCX, atau TXT</label>
                <div class="material-upload-file">
                    <input
                        class="material-upload-file-input"
                        id="file"
                        name="file"
                        type="file"
                        accept=".pdf,.docx,.txt"
                        required
                        aria-describedby="file-help{{ $errors->has('file') ? ' file-error' : '' }}"
                        @if ($errors->has('file')) aria-invalid="true" @endif
                        data-material-upload-input
                    >
                    <svg class="material-upload-file-icon" aria-hidden="true" width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5zm0 0v5h5M12 17v-6m0 0l-2.5 2.5M12 11l2.5 2.5"></path></svg>
                    <span class="material-upload-file-title">Pilih berkas materi</span>
                    <span class="material-upload-file-meta" id="file-help">Format didukung: PDF, DOCX, atau TXT. Maksimal 10 MB.</span>
                    <span class="material-upload-file-button" aria-hidden="true" data-material-upload-button>Pilih berkas</span>
                    <p class="material-upload-file-name" aria-live="polite" data-material-upload-name></p>
                </div>
                @error('file')
                    <div class="error-text" id="file-error">{{ $message }}</div>
                @enderror
                <p class="material-upload-helper">Berkas akan diproses setelah berhasil diunggah.</p>
            </div>

            <div class="material-upload-field">
                <label class="label" for="title">Judul materi</label>
                <input
                    class="material-upload-input"
                    id="title"
                    name="title"
                    type="text"
                    value="{{ old('title') }}"
                    placeholder="Contoh: Informatika Kelas X"
                    required
                    aria-describedby="title-help{{ $errors->has('title') ? ' title-error' : '' }}"
                    @if ($errors->has('title')) aria-invalid="true" @endif
                >
                @error('title')
                    <div class="error-text" id="title-error">{{ $message }}</div>
                @enderror
                <p class="material-upload-helper" id="title-help">Tambahkan judul agar materi lebih mudah dikenali.</p>
            </div>

            <div class="material-upload-actions">
                <button class="material-upload-primary" type="submit">Unggah materi</button>
                <a class="material-upload-cancel" href="{{ route('materials.index') }}"><span>Batal</span></a>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            (function () {
                var input = document.querySelector('[data-material-upload-input]');
                var name = document.querySelector('[data-material-upload-name]');
                var button = document.querySelector('[data-material-upload-button]');
                if (!input || !name) {
                    return;
                }

                function sync() {
                    var file = input.files && input.files.length > 0 ? input.files[0] : null;
                    name.textContent = file ? file.name : '';
                    if (button) {
                        button.textContent = file ? 'Ganti berkas' : 'Pilih berkas';
                    }
                }

                input.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endpush
@endsection
