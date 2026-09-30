@extends('layouts.app')

@section('title', 'Edit materi')

@section('content')
    <div class="page-form">
        <x-ui.page-header>
            Edit materi
        </x-ui.page-header>

        <form method="POST" action="{{ route('materials.update', $material) }}">
            @csrf
            @method('PATCH')

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
            @endif

            <div class="action-stack">
                <x-ui.button type="submit">Simpan perubahan</x-ui.button>
                <x-ui.button variant="tertiary" href="{{ route('materials.show', $material) }}">Kembali ke detail</x-ui.button>
            </div>
        </form>
    </div>
@endsection
