@php
    $statusLabels = [
        'pending' => 'Tertunda',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
    ];
    $statusVariants = [
        'pending' => 'processing',
        'approved' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'neutral',
    ];
    $filters = [
        'pending' => 'Tertunda',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
        'all' => 'Semua',
    ];
@endphp

@extends('layouts.app')

@section('title', 'Verifikasi pembayaran')

@section('content')
    <x-ui.page-header>
        Verifikasi pembayaran
    </x-ui.page-header>

    <div class="action-stack" style="margin-bottom: 16px;">
        @foreach ($filters as $value => $label)
            <x-ui.button variant="secondary" href="{{ route('admin.subscription-upgrades.index', ['status' => $value]) }}">{{ $label }}</x-ui.button>
        @endforeach
    </div>

    @if ($requests->isEmpty())
        <x-ui.empty-state>Tidak ada permintaan.</x-ui.empty-state>
    @else
        <div class="responsive-table table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>User</th>
                        <th>Penawaran</th>
                        <th>Jumlah</th>
                        <th>Status</th>
                        <th>Diminta</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $upgradeRequest)
                        @php
                            $statusValue = $upgradeRequest->status->value;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.subscription-upgrades.show', $upgradeRequest) }}">
                                    {{ $upgradeRequest->reference_code }}
                                </a>
                            </td>
                            <td>{{ $upgradeRequest->user?->email }}</td>
                            <td>{{ $upgradeRequest->offer_name }}</td>
                            <td>Rp{{ number_format($upgradeRequest->price_amount, 0, ',', '.') }}</td>
                            <td>
                                <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                    {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                                </x-ui.status-badge>
                            </td>
                            <td class="muted">{{ $upgradeRequest->requested_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="responsive-summary">
            @foreach ($requests as $upgradeRequest)
                @php
                    $statusValue = $upgradeRequest->status->value;
                @endphp
                <article class="summary-row">
                    <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                        {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                    </x-ui.status-badge>
                    <strong>
                        <a href="{{ route('admin.subscription-upgrades.show', $upgradeRequest) }}">{{ $upgradeRequest->reference_code }}</a>
                    </strong>
                    <p>{{ $upgradeRequest->user?->email }}</p>
                    <p class="muted">{{ $upgradeRequest->offer_name }} · Rp{{ number_format($upgradeRequest->price_amount, 0, ',', '.') }}</p>
                    <p class="muted">{{ $upgradeRequest->requested_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                </article>
            @endforeach
        </div>

        @if ($requests->hasPages())
            <div class="action-stack" style="margin-top: 16px;">
                @if ($requests->onFirstPage())
                    <span class="muted">Sebelumnya</span>
                @else
                    <x-ui.button variant="secondary" href="{{ $requests->previousPageUrl() }}">Sebelumnya</x-ui.button>
                @endif

                @if ($requests->hasMorePages())
                    <x-ui.button variant="secondary" href="{{ $requests->nextPageUrl() }}">Berikutnya</x-ui.button>
                @else
                    <span class="muted">Berikutnya</span>
                @endif
            </div>
        @endif
    @endif
@endsection
