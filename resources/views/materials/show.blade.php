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
    $generationVariants = [
        'queued' => 'neutral',
        'processing' => 'processing',
        'completed' => 'success',
        'failed' => 'danger',
        'cancelled' => 'neutral',
    ];
    $languageLabels = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
    ];
@endphp

@section('title', $material->title)

@section('content')
    <div class="material-detail">
        <div class="material-detail-back">
            <a href="{{ route($material->status->value === 'archived' ? 'materials.archived' : 'materials.index') }}">
                <svg aria-hidden="true" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Kembali ke materi
            </a>
        </div>

        <section class="material-detail-hero">
            <div class="material-detail-heading">
                <h1>{{ $material->title }}</h1>
                <div class="material-detail-status">
                    <x-ui.material-status :material="$material" />
                </div>

                @if ($manualRefresh)
                    <p class="material-detail-status-text">Muat ulang halaman untuk melihat status ekstraksi terbaru.</p>
                @endif
                @if ($extraction === 'failed')
                    <p class="material-detail-status-text" style="color: var(--color-danger);">Ekstraksi gagal.</p>
                @elseif ($extraction === 'completed')
                    <p class="material-detail-status-text">Teks materi telah berhasil diekstraksi dan siap digunakan.</p>
                @endif
            </div>

            <div class="material-detail-actions">
                @if ($canGenerate)
                    <a class="material-detail-primary" href="{{ route('generations.create', $material) }}">
                        <svg aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        Buat soal
                    </a>
                @endif
                @can('restore', $material)
                    <form method="POST" action="{{ route('materials.restore', $material) }}">
                        @csrf
                        <button class="material-detail-primary" type="submit">
                            <svg aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            Pulihkan
                        </button>
                    </form>
                @endcan

                <div class="material-detail-secondary-grid">
                    @can('update', $material)
                        <a class="material-detail-secondary" href="{{ route('materials.edit', $material) }}">
                            <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                            Edit materi
                        </a>
                    @endcan
                    @can('viewProfile', $material)
                        <a class="material-detail-secondary" href="{{ route('materials.profile.show', $material) }}">
                            <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                            Profil materi
                        </a>
                    @endcan
                    @can('viewBlueprints', $material)
                        <a class="material-detail-secondary" href="{{ route('materials.blueprints.index', $material) }}">
                            <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                            Kisi-kisi
                        </a>
                    @endcan
                    @if ($manualRefresh)
                        <a class="material-detail-secondary" href="{{ route('materials.show', $material) }}">
                            <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            Muat ulang
                        </a>
                    @endif
                    @can('archive', $material)
                        <form method="POST" action="{{ route('materials.archive', $material) }}" onsubmit="return confirm('Arsipkan materi ini?')" class="material-detail-action-form">
                            @csrf
                            <button class="material-detail-secondary" type="submit">
                                <svg aria-hidden="true" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                                Arsip
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
        </section>

        <div class="material-detail-workspace">
            <div class="material-detail-main">
                <section class="material-detail-section material-detail-content">
                    <div class="material-detail-section-header">
                        <svg aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9m-6-6l6 6m-6-6v6h6M8 13h8M8 17h8"></path></svg>
                        <h2>Konten materi</h2>
                    </div>
                    <div class="material-detail-content-body">
                        @if (filled($material->content))
                            <div class="material-detail-content-text" id="material-content-text" data-material-content>
                                <div class="content-block">{{ $material->content }}</div>
                            </div>
                            <button class="materials-button-tertiary material-detail-content-toggle" type="button" id="material-content-toggle" aria-expanded="false" aria-controls="material-content-text" data-material-content-toggle hidden>Lihat selengkapnya</button>
                        @else
                            <p class="muted">Belum ada teks yang diekstraksi.</p>
                        @endif
                    </div>
                </section>

                <section class="material-detail-section material-detail-topics">
                    <div class="material-detail-section-header">
                        <svg aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <h2>Topik materi</h2>
                    </div>
                    @if ($topics->isEmpty())
                        <p class="muted" style="padding: 0 20px 20px;">Belum ada topik.</p>
                    @else
                        <div class="material-detail-topic-list">
                            @foreach ($topics as $topic)
                                <article class="material-detail-topic-card">
                                    <form method="POST" action="{{ route('materials.topics.update', [$material, $topic]) }}">
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
                                        <div class="action-stack" style="margin-top: 16px;">
                                            <button class="materials-button-secondary" type="submit" style="min-height:44px; padding:0 12px;">Simpan topik</button>
                                        </div>
                                    </form>
                                    <form method="POST" action="{{ route('materials.topics.destroy', [$material, $topic]) }}" onsubmit="return confirm('Hapus topik ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="ui-button ui-button-danger" type="submit" style="min-height:44px; padding:0 12px; margin-top:8px; border:none; border-radius:12px; cursor:pointer; font-weight:650;">Hapus topik</button>
                                    </form>
                                </article>
                            @endforeach
                        </div>
                    @endif

                    @can('manageTopics', $material)
                        <div class="material-detail-topic-card" style="margin-top: 16px; border-top: 1px dashed var(--color-border); border-radius: 0 0 20px 20px; background: transparent;">
                            <h3 style="margin-top: 0; font-size: 1rem;">Tambah topik</h3>
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
                                    <button class="materials-button-secondary" type="submit" style="min-height:44px; padding:0 12px;">Tambah topik</button>
                                </div>
                            </form>
                        </div>
                    @endcan
                </section>
            </div>

            <div class="material-detail-side">
                @include('materials.blueprint-imports._summary', [
                    'material' => $material,
                    'latestBlueprintImport' => $latestBlueprintImport,
                ])

                <section class="material-detail-section material-detail-generations">
                    <div class="material-detail-section-header">
                        <svg aria-hidden="true" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        <h2>Pembuatan soal terbaru</h2>
                    </div>
                    @if ($recentGenerations->isEmpty())
                        <p class="muted" style="padding: 0 20px 20px;">Belum ada generasi dari materi ini.</p>
                    @else
                        <div class="material-detail-generation-list">
                            @foreach ($recentGenerations as $generation)
                                <article class="material-detail-generation-card">
                                    <div class="material-detail-generation-header">
                                        <h3>Jumlah soal: {{ $generation->question_count }}</h3>
                                        <x-ui.status-badge :variant="$generationVariants[$generation->generation_status->value] ?? 'neutral'">{{ $generationLabels[$generation->generation_status->value] ?? $generation->generation_status->value }}</x-ui.status-badge>
                                    </div>
                                    <p class="muted">Bahasa: {{ $languageLabels[$generation->output_language?->value ?? ''] ?? 'Bahasa tidak dikenali' }}</p>
                                    <p class="muted">{{ $generation->queued_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                                    <a class="materials-button-secondary" href="{{ route('generations.show', $generation) }}" style="margin-top: 12px;">
                                        <span>Buka</span><span aria-hidden="true" style="margin-left:auto;">→</span>
                                    </a>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </div>

    @if (filled($material->content))
    @push('scripts')
        <script>
            (function () {
                var content = document.querySelector('[data-material-content]');
                var toggle = document.querySelector('[data-material-content-toggle]');
                if (!content || !toggle) {
                    return;
                }

                var expandedLabel = 'Tampilkan lebih sedikit';
                var collapsedLabel = 'Lihat selengkapnya';

                function measure() {
                    if (content.classList.contains('is-expanded')) {
                        return;
                    }
                    content.classList.add('is-collapsible');
                    var overflows = content.scrollHeight > content.clientHeight + 1;
                    if (!overflows) {
                        content.classList.remove('is-collapsible');
                    }
                    toggle.hidden = !overflows;
                }

                toggle.addEventListener('click', function () {
                    var expanded = content.classList.toggle('is-expanded');
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    toggle.textContent = expanded ? expandedLabel : collapsedLabel;
                });

                measure();
                window.addEventListener('resize', measure);
            })();
        </script>
    @endpush
    @endif
@endsection
