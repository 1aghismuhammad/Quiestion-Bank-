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
    $statusValue = $upgradeRequest->status->value;
@endphp

@extends('layouts.app')

@section('title', $upgradeRequest->reference_code)

@section('content')
    <x-ui.page-header>
        {{ $upgradeRequest->reference_code }}
        <x-slot:back>
            <x-ui.button variant="tertiary" href="{{ route('admin.subscription-upgrades.index') }}">Kembali ke daftar</x-ui.button>
        </x-slot:back>
        <x-slot:status>
            <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
            </x-ui.status-badge>
        </x-slot:status>
    </x-ui.page-header>

    <x-ui.panel>
        <p><strong>Pengguna:</strong> {{ $upgradeRequest->user?->name }} ({{ $upgradeRequest->user?->email }})</p>
        <p><strong>Penawaran:</strong> {{ $upgradeRequest->offer_name }}</p>
        <p><strong>Durasi:</strong> {{ $upgradeRequest->duration_months }} bulan</p>
        <p><strong>Jumlah:</strong> Rp{{ number_format($upgradeRequest->price_amount, 0, ',', '.') }} {{ $upgradeRequest->currency }}</p>
        <p><strong>Diminta:</strong> {{ $upgradeRequest->requested_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
        @if ($upgradeRequest->reviewed_at)
            <p><strong>Ditinjau:</strong> {{ $upgradeRequest->reviewed_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
        @endif
        @if ($upgradeRequest->reviewer)
            <p><strong>Peninjau:</strong> {{ $upgradeRequest->reviewer->email }}</p>
        @endif
        @if ($upgradeRequest->rejection_reason)
            <p><strong>Alasan penolakan:</strong> {{ $upgradeRequest->rejection_reason }}</p>
        @endif
        @if ($upgradeRequest->approved_subscription_id)
            <p>
                <strong>Masa berlaku:</strong>
                {{ $upgradeRequest->approvedSubscription?->starts_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
                –
                {{ $upgradeRequest->approvedSubscription?->ends_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
            </p>
        @endif
    </x-ui.panel>

    @if ($statusValue === 'pending')
        <div class="action-stack">
            <form method="POST" action="{{ route('admin.subscription-upgrades.approve', $upgradeRequest) }}">
                @csrf
                <x-ui.button type="submit">Setujui</x-ui.button>
            </form>
            <form method="POST" action="{{ route('admin.subscription-upgrades.cancel', $upgradeRequest) }}">
                @csrf
                <x-ui.button variant="secondary" type="submit">Batalkan</x-ui.button>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.subscription-upgrades.reject', $upgradeRequest) }}">
            @csrf
            <x-ui.textarea
                name="rejection_reason"
                id="rejection_reason"
                label="Alasan penolakan"
                :value="old('rejection_reason')"
                required
            />
            <x-ui.button variant="danger" type="submit">Tolak</x-ui.button>
        </form>
    @endif
@endsection
