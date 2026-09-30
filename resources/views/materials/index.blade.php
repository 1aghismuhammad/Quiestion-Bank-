@extends('layouts.app')

@section('title', $archived ? 'Materi terarsip' : 'Materi saya')

@section('content')
    <x-ui.page-header>
        {{ $archived ? 'Materi terarsip' : 'Materi saya' }}
    </x-ui.page-header>

    <div class="action-stack" style="margin-bottom: 24px;">
        @if ($archived)
            <x-ui.button variant="tertiary" href="{{ route('materials.index') }}">Materi aktif</x-ui.button>
        @else
            <x-ui.button href="{{ route('materials.create') }}">Buat materi</x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('materials.archived') }}">Arsip</x-ui.button>
        @endif
    </div>

    @if ($materials->isEmpty())
        <x-ui.empty-state>
            {{ $archived ? 'Tidak ada materi terarsip.' : 'Belum ada materi. Mulai dengan mengunggah PDF, DOCX, atau TXT.' }}
            @unless ($archived)
                <x-slot:action>
                    <x-ui.button href="{{ route('materials.create') }}">Buat materi</x-ui.button>
                </x-slot:action>
            @endunless
        </x-ui.empty-state>
    @else
        <div class="responsive-table table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Diperbarui</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($materials as $material)
                        <tr>
                            <td>{{ $material->title }}</td>
                            <td><x-ui.material-status :material="$material" /></td>
                            <td class="muted">{{ $material->updated_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td>
                                <div class="action-stack">
                                    <x-ui.button variant="secondary" href="{{ route('materials.show', $material) }}">Buka</x-ui.button>
                                    @if ($archived)
                                        @can('restore', $material)
                                            <form method="POST" action="{{ route('materials.restore', $material) }}">
                                                @csrf
                                                <x-ui.button type="submit">Pulihkan</x-ui.button>
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

        <div class="responsive-summary">
            @foreach ($materials as $material)
                <article class="summary-row">
                    <strong>{{ $material->title }}</strong>
                    <x-ui.material-status :material="$material" />
                    <p class="muted">{{ $material->updated_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                    <div class="action-stack">
                        <x-ui.button variant="secondary" href="{{ route('materials.show', $material) }}">Buka</x-ui.button>
                        @if ($archived)
                            @can('restore', $material)
                                <form method="POST" action="{{ route('materials.restore', $material) }}">
                                    @csrf
                                    <x-ui.button type="submit">Pulihkan</x-ui.button>
                                </form>
                            @endcan
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        @if ($materials->hasPages())
            <div class="action-stack" style="margin-top: 16px;">
                @if ($materials->onFirstPage())
                    <span class="muted">Sebelumnya</span>
                @else
                    <x-ui.button variant="secondary" href="{{ $materials->previousPageUrl() }}">Sebelumnya</x-ui.button>
                @endif
                @if ($materials->hasMorePages())
                    <x-ui.button variant="secondary" href="{{ $materials->nextPageUrl() }}">Berikutnya</x-ui.button>
                @else
                    <span class="muted">Berikutnya</span>
                @endif
            </div>
        @endif
    @endif
@endsection
