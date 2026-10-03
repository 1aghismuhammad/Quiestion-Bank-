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
    <div class="question-set-index-page">
        <x-ui.page-header>
            Bank soal
            @unless ($questionSets->isEmpty())
                <x-slot:supporting>Daftar set soal yang telah Anda simpan dari hasil pembuatan soal.</x-slot:supporting>
            @endunless
        </x-ui.page-header>

        @if ($questionSets->isEmpty())
            <section class="question-set-index-empty">
                <h2>Belum ada set soal.</h2>
                <p>Set soal yang dibuat dari kisi-kisi juga muncul di sini setelah disimpan dari halaman hasil generasi.</p>
                <x-ui.button href="{{ route('generations.index') }}">Riwayat pembuatan dari materi</x-ui.button>
            </section>
        @else
            <div class="question-set-index-list responsive-table table-wrap">
                <table class="table question-set-index-table">
                    <thead>
                        <tr>
                            <th scope="col">Judul</th>
                            <th scope="col">Status</th>
                            <th scope="col">Jumlah soal</th>
                            <th scope="col">Dibuat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($questionSets as $questionSet)
                            @php
                                $statusValue = $questionSet->status->value;
                            @endphp
                            <tr>
                                <td class="question-set-index-title-cell">
                                    <a class="question-set-index-title" href="{{ route('question-sets.show', $questionSet) }}">{{ $questionSet->title }}</a>
                                </td>
                                <td>
                                    <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                        {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                                    </x-ui.status-badge>
                                </td>
                                <td>{{ $questionSet->total_question }}</td>
                                <td class="question-set-index-date">{{ $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="responsive-summary question-set-index-cards">
                @foreach ($questionSets as $questionSet)
                    @php
                        $statusValue = $questionSet->status->value;
                    @endphp
                    <article class="question-set-index-card">
                        <div>
                            <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                            </x-ui.status-badge>
                        </div>
                        <h2 class="question-set-index-card-title">{{ $questionSet->title }}</h2>
                        <p class="question-set-index-card-meta">
                            {{ $questionSet->total_question }} soal
                            · {{ $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
                        </p>
                        <a class="question-set-index-open" href="{{ route('question-sets.show', $questionSet) }}" aria-label="Buka {{ $questionSet->title }}">Buka</a>
                    </article>
                @endforeach
            </div>

            @if ($questionSets->hasPages())
                <nav class="question-set-index-pages" aria-label="Halaman bank soal">
                    @if ($questionSets->onFirstPage())
                        <span class="question-set-index-page-disabled" aria-disabled="true">Sebelumnya</span>
                    @else
                        <x-ui.button variant="secondary" href="{{ $questionSets->previousPageUrl() }}">Sebelumnya</x-ui.button>
                    @endif

                    @if ($questionSets->hasMorePages())
                        <x-ui.button variant="secondary" href="{{ $questionSets->nextPageUrl() }}">Berikutnya</x-ui.button>
                    @else
                        <span class="question-set-index-page-disabled" aria-disabled="true">Berikutnya</span>
                    @endif
                </nav>
            @endif
        @endif
    </div>
@endsection
