@extends('layouts.app')

@php
    $extraction = $material->extraction_status->value;
    $manualRefresh = in_array($extraction, ['pending', 'processing', 'failed'], true);
    $generationLabels = [
        'queued' => 'Menunggu diproses',
        'processing' => 'Sedang diproses',
        'completed' => 'Selesai',
        'failed' => 'Gagal',
        'cancelled' => 'Dibatalkan',
    ];
    $languageLabels = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];
@endphp

@section('title', $material->title)

@section('content')
    <p style="margin-bottom: 16px;">
        <a href="{{ route($material->status->value === 'archived' ? 'materials.archived' : 'materials.index') }}">Kembali ke daftar</a>
    </p>

    <h1>{{ $material->title }}</h1>
    <p><x-ui.material-status :material="$material" /></p>

    @if ($manualRefresh)
        <p class="muted">Muat ulang halaman untuk melihat status ekstraksi terbaru.</p>
    @endif

    @if ($extraction === 'failed')
        <p>Ekstraksi gagal.</p>
    @endif

    <div class="action-stack" style="margin: 16px 0 24px;">
        @can('update', $material)
            <x-ui.button variant="secondary" href="{{ route('materials.edit', $material) }}">Edit</x-ui.button>
        @endcan
        @can('viewProfile', $material)
            <x-ui.button variant="secondary" href="{{ route('materials.profile.show', $material) }}">Profil materi</x-ui.button>
        @endcan
        @can('viewBlueprints', $material)
            <x-ui.button variant="secondary" href="{{ route('materials.blueprints.index', $material) }}">Kisi-kisi</x-ui.button>
        @endcan
        @if ($manualRefresh)
            <x-ui.button variant="secondary" href="{{ route('materials.show', $material) }}">Muat ulang</x-ui.button>
        @endif
        @if ($canGenerate)
            <x-ui.button href="{{ route('generations.create', $material) }}">Buat soal</x-ui.button>
        @endif
        @can('restore', $material)
            <form method="POST" action="{{ route('materials.restore', $material) }}">
                @csrf
                <x-ui.button type="submit">Pulihkan</x-ui.button>
            </form>
        @endcan
    </div>

    @can('archive', $material)
        <form method="POST" action="{{ route('materials.archive', $material) }}" onsubmit="return confirm('Arsipkan materi ini?')" style="margin-bottom: 24px;">
            @csrf
            <x-ui.button variant="danger" type="submit">Arsipkan</x-ui.button>
        </form>
    @endcan

    @include('materials.blueprint-imports._summary', [
        'material' => $material,
        'latestBlueprintImport' => $latestBlueprintImport,
    ])

    <h2>Konten</h2>
    @if (filled($material->content))
        <div class="content-block">{{ $material->content }}</div>
    @else
        <p class="muted">Belum ada teks yang diekstraksi.</p>
    @endif

    <h2>Generasi terbaru</h2>
    @if ($recentGenerations->isEmpty())
        <p class="muted">Belum ada generasi dari materi ini.</p>
    @else
        <div class="responsive-table table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Jumlah soal</th>
                        <th>Bahasa</th>
                        <th>Antrian</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentGenerations as $generation)
                        <tr>
                            <td>
                                <a href="{{ route('generations.show', $generation) }}">{{ $generationLabels[$generation->generation_status->value] ?? $generation->generation_status->value }}</a>
                            </td>
                            <td>{{ $generation->question_count }}</td>
                            <td>{{ $languageLabels[$generation->output_language?->value ?? ''] ?? 'Bahasa tidak dikenali' }}</td>
                            <td class="muted">{{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="responsive-summary">
            @foreach ($recentGenerations as $generation)
                <article class="summary-row">
                    <a href="{{ route('generations.show', $generation) }}">{{ $generationLabels[$generation->generation_status->value] ?? $generation->generation_status->value }}</a>
                    <p>Jumlah soal: {{ $generation->question_count }}</p>
                    <p class="muted">{{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                </article>
            @endforeach
        </div>
    @endif

    <h2>Topik</h2>
    @if ($topics->isEmpty())
        <p class="muted">Belum ada topik.</p>
    @else
        @foreach ($topics as $topic)
            <form method="POST" action="{{ route('materials.topics.update', [$material, $topic]) }}" style="margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--color-border);">
                @csrf
                @method('PATCH')
                <div class="field-grid">
                    <div>
                        <label class="label" for="topic_name_{{ $topic->topic_id }}">Nama topik</label>
                        <input class="input" id="topic_name_{{ $topic->topic_id }}" name="topic_name" type="text" value="{{ old('topic_name', $topic->topic_name) }}" required>
                    </div>
                    <div>
                        <label class="label" for="focus_area_{{ $topic->topic_id }}">Area fokus</label>
                        <input class="input" id="focus_area_{{ $topic->topic_id }}" name="focus_area" type="text" value="{{ old('focus_area', $topic->focus_area) }}">
                    </div>
                    <div>
                        <label class="label" for="chapter_{{ $topic->topic_id }}">Bab</label>
                        <input class="input" id="chapter_{{ $topic->topic_id }}" name="chapter" type="text" value="{{ old('chapter', $topic->chapter) }}">
                    </div>
                    <div>
                        <label class="label" for="sub_chapter_{{ $topic->topic_id }}">Sub-bab</label>
                        <input class="input" id="sub_chapter_{{ $topic->topic_id }}" name="sub_chapter" type="text" value="{{ old('sub_chapter', $topic->sub_chapter) }}">
                    </div>
                    <div>
                        <label class="label" for="sort_order_{{ $topic->topic_id }}">Urutan</label>
                        <input class="input" id="sort_order_{{ $topic->topic_id }}" name="sort_order" type="number" min="0" value="{{ old('sort_order', $topic->sort_order) }}">
                    </div>
                    <div>
                        <label class="label" for="page_start_{{ $topic->topic_id }}">Halaman awal</label>
                        <input class="input" id="page_start_{{ $topic->topic_id }}" name="page_start" type="number" min="1" value="{{ old('page_start', $topic->page_start) }}">
                    </div>
                    <div>
                        <label class="label" for="page_end_{{ $topic->topic_id }}">Halaman akhir</label>
                        <input class="input" id="page_end_{{ $topic->topic_id }}" name="page_end" type="number" min="1" value="{{ old('page_end', $topic->page_end) }}">
                    </div>
                </div>
                <div class="action-stack" style="margin-top: 12px;">
                    <x-ui.button variant="secondary" type="submit">Simpan topik</x-ui.button>
                </div>
            </form>
            <form method="POST" action="{{ route('materials.topics.destroy', [$material, $topic]) }}" onsubmit="return confirm('Hapus topik ini?')" style="margin-bottom: 24px;">
                @csrf
                @method('DELETE')
                <x-ui.button variant="danger" type="submit">Hapus topik</x-ui.button>
            </form>
        @endforeach
    @endif

    @can('manageTopics', $material)
        <h2>Tambah topik</h2>
        <form method="POST" action="{{ route('materials.topics.store', $material) }}">
            @csrf
            <div class="field-grid">
                <div>
                    <label class="label" for="topic_name">Nama topik</label>
                    <input class="input" id="topic_name" name="topic_name" type="text" value="{{ old('topic_name') }}" required>
                    @error('topic_name')
                        <div class="error-text">{{ $message }}</div>
                    @enderror
                </div>
                <div>
                    <label class="label" for="focus_area">Area fokus</label>
                    <input class="input" id="focus_area" name="focus_area" type="text" value="{{ old('focus_area') }}">
                </div>
                <div>
                    <label class="label" for="chapter">Bab</label>
                    <input class="input" id="chapter" name="chapter" type="text" value="{{ old('chapter') }}">
                </div>
                <div>
                    <label class="label" for="sub_chapter">Sub-bab</label>
                    <input class="input" id="sub_chapter" name="sub_chapter" type="text" value="{{ old('sub_chapter') }}">
                </div>
                <div>
                    <label class="label" for="sort_order">Urutan</label>
                    <input class="input" id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order', 0) }}">
                </div>
                <div>
                    <label class="label" for="page_start">Halaman awal</label>
                    <input class="input" id="page_start" name="page_start" type="number" min="1" value="{{ old('page_start') }}">
                </div>
                <div>
                    <label class="label" for="page_end">Halaman akhir</label>
                    <input class="input" id="page_end" name="page_end" type="number" min="1" value="{{ old('page_end') }}">
                </div>
            </div>
            @error('page_end')
                <div class="error-text">{{ $message }}</div>
            @enderror
            <div class="action-stack" style="margin-top: 16px;">
                <x-ui.button type="submit">Tambah topik</x-ui.button>
            </div>
        </form>
    @endcan
@endsection
