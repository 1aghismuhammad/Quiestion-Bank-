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
    <div class="container" style="max-width: 64rem; margin-inline: auto; margin-top: 20px;">
        <x-ui.admin-nav />

        <div class="ui-page-header" style="margin-top: 24px; margin-bottom: 24px;">
            <h1>Verifikasi pembayaran</h1>
        </div>

        <div class="action-stack" style="margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 8px;">
            @php
                $currentStatus = $status ?? 'all';
            @endphp
            @foreach ($filters as $value => $label)
                @if ($currentStatus === $value)
                    <x-ui.button href="{{ route('admin.subscription-upgrades.index', ['status' => $value]) }}" style="border-radius: 999px;">{{ $label }}</x-ui.button>
                @else
                    <x-ui.button variant="secondary" href="{{ route('admin.subscription-upgrades.index', ['status' => $value]) }}" style="border-radius: 999px;">{{ $label }}</x-ui.button>
                @endif
            @endforeach
        </div>

        @if ($requests->isEmpty())
            <x-ui.empty-state>Tidak ada permintaan.</x-ui.empty-state>
        @else
            <div class="responsive-table table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Referensi</th>
                            <th>Pengguna</th>
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
                                    <a href="{{ route('admin.subscription-upgrades.show', $upgradeRequest) }}" style="font-weight: 650; color: #5145dc; text-decoration: none;">
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

            <div class="responsive-summary" style="margin-top: 16px;">
                @foreach ($requests as $upgradeRequest)
                    @php
                        $statusValue = $upgradeRequest->status->value;
                    @endphp
                    <article class="summary-row" style="padding: 16px; border-radius: 16px; border: 1px solid #ebe6dc; background: #ffffff;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                            <strong style="font-size: 1rem;">
                                <a href="{{ route('admin.subscription-upgrades.show', $upgradeRequest) }}" style="color: var(--color-text); text-decoration: none;">{{ $upgradeRequest->reference_code }}</a>
                            </strong>
                            <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                            </x-ui.status-badge>
                        </div>
                        <p style="margin: 0; overflow-wrap: anywhere;">{{ $upgradeRequest->user?->email }}</p>
                        <p class="muted" style="margin: 0;">{{ $upgradeRequest->offer_name }} · Rp{{ number_format($upgradeRequest->price_amount, 0, ',', '.') }}</p>
                        <p class="muted" style="margin: 0;">{{ $upgradeRequest->requested_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                        <div style="margin-top: 8px;">
                            <x-ui.button variant="secondary" href="{{ route('admin.subscription-upgrades.show', $upgradeRequest) }}" style="width: 100%;">Lihat detail</x-ui.button>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($requests->hasPages())
                <div class="action-stack" style="margin-top: 20px;">
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
    </div>
@endsection
