@extends('layouts.app')

@section('title', 'Edit materi')

@section('content')
    <div class="material-edit-page">
        <a class="material-edit-back" href="{{ route('materials.show', $material) }}">
            <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            <span>Kembali ke detail</span>
        </a>

        <x-ui.page-header>
            Edit materi
            <x-slot:supporting>Perbarui informasi materi.</x-slot:supporting>
        </x-ui.page-header>

        <form class="material-edit-panel" method="POST" action="{{ route('materials.update', $material) }}">
            @csrf
            @method('PATCH')

            <h2>Informasi materi</h2>

            <x-ui.text-input
                name="title"
                id="title"
                label="Judul"
                :value="old('title', $material->title)"
                required
            />

            @if ($isText)
                <x-ui.textarea
                    name="content"
                    id="content"
                    label="Konten"
                    :value="old('content', $material->content)"
                    required
                />
            @else
                <p class="material-edit-note">Materi ini berasal dari berkas unggahan. Hanya judul yang dapat diperbarui.</p>
            @endif

            <button class="material-edit-save" type="submit">Simpan perubahan</button>
        </form>
    </div>
@endsection
