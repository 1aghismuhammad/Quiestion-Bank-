@php
    $statusLabels = [
        'draft' => 'Draf',
        'generating' => 'Menghasilkan',
        'review' => 'Tinjauan',
        'published' => 'Terbit',
        'archived' => 'Arsip',
    ];
@endphp

@extends('layouts.app')

@section('title', 'Question Bank')

@section('content')
    <p class="muted">QUESTION BANK</p>
    <h1>Bank soal</h1>

    @if ($questionSets->isEmpty())
        <div class="card">
            <p>Belum ada soal di Question Bank.</p>
            <p class="muted">Simpan generasi yang selesai dari halaman generasi soal.</p>
            <a class="button" href="{{ route('generations.index') }}">Lihat riwayat generasi</a>
        </div>
    @else
        <div class="card">
            <table class="table">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Status</th>
                        <th>Jumlah soal</th>
                        <th>Dibuat</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($questionSets as $questionSet)
                        <tr>
                            <td>
                                <a href="{{ route('question-sets.show', $questionSet) }}">{{ $questionSet->title }}</a>
                            </td>
                            <td>
                                <span class="status">{{ $statusLabels[$questionSet->status->value] ?? $questionSet->status->value }}</span>
                                <span class="muted">({{ $questionSet->status->value }})</span>
                            </td>
                            <td>{{ $questionSet->total_question }}</td>
                            <td class="muted">{{ $questionSet->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td>
                                <a href="{{ route('question-sets.show', $questionSet) }}">Lihat</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($questionSets->hasPages())
                <div class="actions" style="margin-top: 16px;">
                    @if ($questionSets->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <a class="button button-secondary" href="{{ $questionSets->previousPageUrl() }}">Sebelumnya</a>
                    @endif

                    @if ($questionSets->hasMorePages())
                        <a class="button button-secondary" href="{{ $questionSets->nextPageUrl() }}">Berikutnya</a>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        </div>
    @endif
@endsection
