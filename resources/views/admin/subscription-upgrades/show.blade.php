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
    <div class="container" style="max-width: 52rem; margin-inline: auto; margin-top: 20px;">
        <x-ui.admin-nav />

        <div class="ui-page-header" style="margin-top: 24px; margin-bottom: 24px;">
            <div>
                <h1 style="display: flex; align-items: center; gap: 12px; margin: 0;">
                    {{ $upgradeRequest->reference_code }}
                    <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                        {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                    </x-ui.status-badge>
                </h1>
            </div>
            <div class="ui-page-header-actions">
                <x-ui.button variant="tertiary" href="{{ route('admin.subscription-upgrades.index') }}">Kembali ke daftar</x-ui.button>
            </div>
        </div>

        <div class="card" style="padding: 24px; margin-bottom: 24px;">
            <h2 style="font-size: 1.125rem; margin: 0 0 16px;">Detail Permintaan</h2>
            <div style="display: grid; gap: 12px;">
                <p style="margin: 0; overflow-wrap: anywhere;"><strong class="muted">Pengguna:</strong><br>{{ $upgradeRequest->user?->name }} ({{ $upgradeRequest->user?->email }})</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 12px;">
                    <p style="margin: 0;"><strong class="muted">Penawaran:</strong><br>{{ $upgradeRequest->offer_name }}</p>
                    <p style="margin: 0;"><strong class="muted">Durasi:</strong><br>{{ $upgradeRequest->duration_months }} bulan</p>
                    <p style="margin: 0;"><strong class="muted">Jumlah:</strong><br>Rp{{ number_format($upgradeRequest->price_amount, 0, ',', '.') }} {{ $upgradeRequest->currency }}</p>
                </div>
                <p style="margin: 0;"><strong class="muted">Diminta:</strong><br>{{ $upgradeRequest->requested_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>

                @if ($upgradeRequest->reviewed_at || $upgradeRequest->reviewer || $upgradeRequest->rejection_reason || $upgradeRequest->approved_subscription_id)
                    <hr style="border: 0; border-top: 1px solid #ebe6dc; margin: 8px 0;">
                @endif

                @if ($upgradeRequest->reviewed_at)
                    <p style="margin: 0;"><strong class="muted">Ditinjau:</strong><br>{{ $upgradeRequest->reviewed_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
                @endif
                @if ($upgradeRequest->reviewer)
                    <p style="margin: 0; overflow-wrap: anywhere;"><strong class="muted">Peninjau:</strong><br>{{ $upgradeRequest->reviewer->email }}</p>
                @endif
                @if ($upgradeRequest->rejection_reason)
                    <p style="margin: 0; overflow-wrap: anywhere; color: #9f2424;"><strong class="muted">Alasan penolakan:</strong><br>{{ $upgradeRequest->rejection_reason }}</p>
                @endif
                @if ($upgradeRequest->approved_subscription_id)
                    <div style="background: #fbfaf7; border: 1px solid #ebe6dc; border-radius: 12px; padding: 12px; margin-top: 4px;">
                        <p style="margin: 0;">
                            <strong class="muted">Masa berlaku:</strong><br>
                            {{ $upgradeRequest->approvedSubscription?->starts_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
                            –
                            {{ $upgradeRequest->approvedSubscription?->ends_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
                        </p>
                    </div>
                @endif
            </div>
        </div>

        @if ($statusValue === 'pending')
            <div class="card" style="padding: 24px; background: #faf9f6;">
                <h2 style="font-size: 1.125rem; margin: 0 0 16px;">Tindakan</h2>

                <div class="action-stack" style="margin-bottom: 24px;">
                    <form method="POST" action="{{ route('admin.subscription-upgrades.approve', $upgradeRequest) }}" onsubmit="return confirm('Setujui permintaan ini dan berikan langganan Pro?')">
                        @csrf
                        <x-ui.button type="submit">Setujui</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('admin.subscription-upgrades.cancel', $upgradeRequest) }}" onsubmit="return confirm('Batalkan permintaan ini tanpa memberikan langganan?')">
                        @csrf
                        <x-ui.button variant="secondary" type="submit">Batalkan permintaan</x-ui.button>
                    </form>
                </div>

                <div style="border-top: 1px solid #ebe6dc; padding-top: 24px;">
                    <form method="POST" action="{{ route('admin.subscription-upgrades.reject', $upgradeRequest) }}" onsubmit="return confirm('Tolak permintaan upgrade ini? Tindakan ini akan mengubah status permintaan menjadi Ditolak.')" style="display: grid; gap: 12px;">
                        @csrf
                        <x-ui.textarea
                            name="rejection_reason"
                            id="rejection_reason"
                            label="Alasan penolakan"
                            :value="old('rejection_reason')"
                            placeholder="Alasan penolakan..."
                            required
                            style="min-height: 4rem;"
                        />
                        <div class="action-stack">
                            <x-ui.button variant="danger" type="submit">Tolak permintaan</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
