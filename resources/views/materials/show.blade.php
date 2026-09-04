@php
    $extractionStatus = $material->extraction_status;
    $extractionLabel = match ($extractionStatus->value) {
        'pending' => 'Menunggu ekstraksi',
        'processing' => 'Sedang diproses',
        'completed' => 'Selesai',
        'failed' => 'Ekstraksi gagal',
        'not_required' => 'Tidak diperlukan',
        default => $extractionStatus->value,
    };

    $extractionClass = match ($extractionStatus->value) {
        'pending', 'processing' => 'status status-warn',
        'failed' => 'status status-error',
        'not_required' => 'status status-muted',
        default => 'status',
    };
@endphp

@extends('layouts.app')

@section('title', $material->title)

@section('content')
    <div class="actions" style="margin-bottom: 16px;">
        <a href="{{ route($material->status->value === 'archived' ? 'materials.archived' : 'materials.index') }}">Kembali ke daftar</a>
    </div>

    <p class="muted">DETAIL MATERI</p>
    <h1>{{ $material->title }}</h1>

    <div class="card" style="margin-bottom: 20px;">
        <p><strong>Sumber:</strong> {{ $material->source_type->value }}</p>
        <p>
            <strong>Status:</strong>
            <span class="{{ $material->status->value === 'archived' ? 'status status-muted' : 'status' }}">
                {{ $material->status->value }}
            </span>
        </p>
        <p>
            <strong>Ekstraksi:</strong>
            <span class="{{ $extractionClass }}">{{ $extractionLabel }}</span>
        </p>
        @if ($material->file_name)
            <p><strong>Nama file:</strong> {{ $material->file_name }}</p>
        @endif
        @if ($material->mime_type)
            <p><strong>Tipe:</strong> {{ $material->mime_type }}</p>
        @endif
        <p class="muted">Dibuat {{ $material->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>

        @if (in_array($material->extraction_status->value, ['pending', 'processing'], true))
            <p class="muted">Muat ulang halaman untuk melihat status ekstraksi terbaru.</p>
        @endif

        @if ($material->extraction_status->value === 'failed')
            <p>Ekstraksi gagal.</p>
        @endif

        <div class="actions">
            @can('update', $material)
                <a class="button button-secondary" href="{{ route('materials.edit', $material) }}">Edit</a>
            @endcan

            @can('archive', $material)
                <form method="POST" action="{{ route('materials.archive', $material) }}" onsubmit="return confirm('Arsipkan materi ini?')">
                    @csrf
                    <button class="button button-secondary" type="submit">Arsipkan</button>
                </form>
            @endcan

            @can('restore', $material)
                <form method="POST" action="{{ route('materials.restore', $material) }}">
                    @csrf
                    <button class="button" type="submit">Pulihkan</button>
                </form>
            @endcan

            @can('viewProfile', $material)
                <a class="button button-secondary" href="{{ route('materials.profile.show', $material) }}">Profil materi</a>
            @endcan

            @if ($canGenerate)
                <a class="button" href="{{ route('generations.create', $material) }}">Generate Questions</a>
            @endif
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h2>Konten</h2>
        @if (filled($material->content))
            <div class="content-block">{{ $material->content }}</div>
        @else
            <p class="muted">Belum ada konten teks.</p>
        @endif
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h2>Generasi terbaru</h2>
        @if ($recentGenerations->isEmpty())
            <p class="muted">Belum ada generasi dari materi ini.</p>
        @else
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
                                <a href="{{ route('generations.show', $generation) }}">{{ $generation->generation_status->value }}</a>
                            </td>
                            <td>{{ $generation->question_count }}</td>
                            <td>{{ $generation->output_language?->value }}</td>
                            <td class="muted">{{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <h2>Topik</h2>

        @if ($topics->isEmpty())
            <p class="muted">Belum ada topik.</p>
        @else
            @foreach ($topics as $topic)
                <form method="POST" action="{{ route('materials.topics.update', [$material, $topic]) }}" style="margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #dce3ee;">
                    @csrf
                    @method('PATCH')

                    <div class="field-grid">
                        <div>
                            <label class="label" for="topic_name_{{ $topic->topic_id }}">Nama topik</label>
                            <input class="input" id="topic_name_{{ $topic->topic_id }}" name="topic_name" type="text" value="{{ old('topic_name', $topic->topic_name) }}" required>
                        </div>
                        <div>
                            <label class="label" for="focus_area_{{ $topic->topic_id }}">Focus area</label>
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

                    <div class="actions" style="margin-top: 12px;">
                        <button class="button button-secondary" type="submit">Simpan topik</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('materials.topics.destroy', [$material, $topic]) }}" onsubmit="return confirm('Hapus topik ini?')" style="margin-bottom: 24px;">
                    @csrf
                    @method('DELETE')
                    <button class="button button-danger" type="submit">Hapus topik</button>
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
                        <label class="label" for="focus_area">Focus area</label>
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
                <button class="button" style="margin-top: 16px;" type="submit">Tambah topik</button>
            </form>
        @endcan
    </div>
@endsection
