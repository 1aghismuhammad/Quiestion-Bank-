@extends('layouts.app')

@section('title', $archived ? 'Materi terarsip' : 'Materi')

@section('content')
    <div class="actions" style="margin-bottom: 20px; justify-content: space-between;">
        <div>
            <p class="muted">{{ $archived ? 'ARSIP MATERI' : 'MATERIAL MANAGEMENT' }}</p>
            <h1>{{ $archived ? 'Materi terarsip' : 'Materi saya' }}</h1>
        </div>
        <div class="actions">
            @if ($archived)
                <a class="button button-secondary" href="{{ route('materials.index') }}">Materi aktif</a>
            @else
                <a class="button button-secondary" href="{{ route('materials.archived') }}">Arsip</a>
                <a class="button" href="{{ route('materials.create') }}">Buat materi</a>
            @endif
        </div>
    </div>

    @if ($materials->isEmpty())
        <div class="card">
            @if ($archived)
                <p>Tidak ada materi terarsip.</p>
            @else
                <p>Belum ada materi. Unggah PDF, DOCX, atau TXT.</p>
                <a class="button" href="{{ route('materials.create') }}">Buat materi</a>
            @endif
        </div>
    @else
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th>Ekstraksi</th>
                        <th>Topik</th>
                        <th>Diperbarui</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($materials as $material)
                        <tr>
                            <td>
                                <a href="{{ route('materials.show', $material) }}">{{ $material->title }}</a>
                            </td>
                            <td>{{ $material->source_type->value }}</td>
                            <td>{{ $material->status->value }}</td>
                            <td>{{ $material->extraction_status->value }}</td>
                            <td>{{ $material->topics_count }}</td>
                            <td class="muted">{{ $material->updated_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($materials->hasPages())
                <div class="actions" style="margin-top: 16px;">
                    @if ($materials->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <a class="button button-secondary" href="{{ $materials->previousPageUrl() }}">Sebelumnya</a>
                    @endif

                    @if ($materials->hasMorePages())
                        <a class="button button-secondary" href="{{ $materials->nextPageUrl() }}">Berikutnya</a>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        </div>
    @endif
@endsection
