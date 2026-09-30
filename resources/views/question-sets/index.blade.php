@php
    $statusLabels = [
        'draft' => 'Draf',
        'generating' => 'Dihasilkan',
        'review' => 'Ditinjau',
        'published' => 'Terbit',
        'archived' => 'Diarsipkan',
    ];
    $statusVariants = [
        'draft' => 'neutral',
        'generating' => 'processing',
        'review' => 'info',
        'published' => 'success',
        'archived' => 'neutral',
    ];
@endphp

@extends('layouts.app')

@section('title', 'Bank soal')

@section('content')
    <x-ui.page-header>
        Bank soal
    </x-ui.page-header>

    @if ($questionSets->isEmpty())
        <x-ui.empty-state>
            Belum ada set soal.
            <x-slot:action>
                <x-ui.button variant="tertiary" href="{{ route('generations.index') }}">Riwayat pembuatan dari materi</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
        <p class="muted">Set soal yang dibuat dari kisi-kisi juga muncul di sini setelah disimpan dari halaman hasil generasi.</p>
    @else
        <div class="responsive-table table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Jumlah soal</th>
                        <th>Dibuat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($questionSets as $questionSet)
                        @php
                            $statusValue = $questionSet->status->value;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('question-sets.show', $questionSet) }}">{{ $questionSet->title }}</a>
                            </td>
                            <td>
                                <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                    {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                                </x-ui.status-badge>
                            </td>
                            <td>{{ $questionSet->total_question }}</td>
                            <td class="muted">{{ $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="responsive-summary">
            @foreach ($questionSets as $questionSet)
                @php
                    $statusValue = $questionSet->status->value;
                @endphp
                <article class="summary-row">
                    <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                        {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                    </x-ui.status-badge>
                    <strong><a href="{{ route('question-sets.show', $questionSet) }}">{{ $questionSet->title }}</a></strong>
                    <p class="muted">{{ $questionSet->total_question }} soal</p>
                    <p class="muted">{{ $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                </article>
            @endforeach
        </div>

        @if ($questionSets->hasPages())
            <div class="action-stack" style="margin-top: 16px;">
                @if ($questionSets->onFirstPage())
                    <span class="muted">Sebelumnya</span>
                @else
                    <x-ui.button variant="secondary" href="{{ $questionSets->previousPageUrl() }}">Sebelumnya</x-ui.button>
                @endif

                @if ($questionSets->hasMorePages())
                    <x-ui.button variant="secondary" href="{{ $questionSets->nextPageUrl() }}">Berikutnya</x-ui.button>
                @else
                    <span class="muted">Berikutnya</span>
                @endif
            </div>
        @endif
    @endif
@endsection
