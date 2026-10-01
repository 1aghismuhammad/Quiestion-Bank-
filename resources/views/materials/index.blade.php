@extends('layouts.app')

@section('title', $archived ? 'Materi terarsip' : 'Materi saya')

@section('content')
    <div class="materials-page">
        <header class="materials-page-header">
            <div class="materials-header-text">
                <h1>{{ $archived ? 'Materi terarsip' : 'Materi saya' }}</h1>
                @if ($archived)
                    <p>Materi pembelajaran yang telah diarsipkan.</p>
                @else
                    <p>Kelola materi pembelajaran yang digunakan sebagai dasar pembuatan soal.</p>
                @endif
            </div>

            <div class="materials-page-actions">
                @if ($archived)
                    <a class="materials-button-secondary" href="{{ route('materials.index') }}">Materi aktif</a>
                @else
                    <a class="materials-button-primary" href="{{ route('materials.create') }}">Buat materi</a>
                    <a class="materials-button-secondary" href="{{ route('materials.archived') }}">Arsip</a>
                @endif
            </div>
        </header>

        @if ($materials->isEmpty())
            <div class="materials-empty">
                <p>{{ $archived ? 'Tidak ada materi terarsip.' : 'Belum ada materi. Mulai dengan mengunggah PDF, DOCX, atau TXT.' }}</p>
                @unless ($archived)
                    <a class="materials-button-primary" href="{{ route('materials.create') }}">Buat materi</a>
                @endunless
            </div>
        @else
            <div class="materials-list-panel">
                <div class="materials-desktop-list">
                    <table class="materials-table">
                        <thead>
                            <tr>
                                <th>Judul</th>
                                <th>Status</th>
                                <th>Diperbarui</th>
                                <th class="materials-table-actions">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($materials as $material)
                                <tr class="materials-row">
                                    <td class="materials-cell-title">
                                        <div class="materials-title-content">
                                            <span class="materials-title-icon" aria-hidden="true">
                                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                            </span>
                                            <span class="materials-title-text">{{ $material->title }}</span>
                                        </div>
                                    </td>
                                    <td><x-ui.material-status :material="$material" /></td>
                                    <td class="materials-meta">{{ $material->updated_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                                    <td class="materials-table-actions">
                                        <div class="materials-actions-group">
                                            <a class="materials-button-tertiary" href="{{ route('materials.show', $material) }}">Buka</a>
                                            @if ($archived)
                                                @can('restore', $material)
                                                    <form method="POST" action="{{ route('materials.restore', $material) }}">
                                                        @csrf
                                                        <button class="materials-button-tertiary" type="submit">Pulihkan</button>
                                                    </form>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="materials-mobile-list">
                    @foreach ($materials as $material)
                        <article class="materials-mobile-item">
                            <div class="materials-mobile-header">
                                <h2 class="materials-title-text">{{ $material->title }}</h2>
                                <div><x-ui.material-status :material="$material" /></div>
                            </div>
                            <p class="materials-meta">Diperbarui {{ $material->updated_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                            <div class="materials-mobile-actions">
                                <a class="materials-button-secondary materials-mobile-action" href="{{ route('materials.show', $material) }}"><span>Buka</span><span aria-hidden="true">→</span></a>
                                @if ($archived)
                                    @can('restore', $material)
                                        <form method="POST" action="{{ route('materials.restore', $material) }}" style="width:100%">
                                            @csrf
                                            <button class="materials-button-secondary materials-mobile-action" type="submit">Pulihkan</button>
                                        </form>
                                    @endcan
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>

            @if ($materials->hasPages())
                <div class="materials-pagination">
                    @if ($materials->onFirstPage())
                        <span class="materials-pagination-disabled">Sebelumnya</span>
                    @else
                        <a class="materials-button-secondary materials-pagination-link" href="{{ $materials->previousPageUrl() }}">Sebelumnya</a>
                    @endif

                    @if ($materials->hasMorePages())
                        <a class="materials-button-secondary materials-pagination-link" href="{{ $materials->nextPageUrl() }}">Berikutnya</a>
                    @else
                        <span class="materials-pagination-disabled">Berikutnya</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endsection