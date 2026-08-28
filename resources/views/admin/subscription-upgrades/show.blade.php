@extends('layouts.app')

@section('title', 'Detail upgrade')

@section('content')
    <p class="muted">ADMIN</p>
    <h1>{{ $upgradeRequest->reference_code }}</h1>
    <p><a href="{{ route('admin.subscription-upgrades.index') }}">Kembali ke daftar</a></p>

    <div class="card" style="margin-bottom: 20px;">
        <p><strong>User:</strong> {{ $upgradeRequest->user?->name }} ({{ $upgradeRequest->user?->email }})</p>
        <p><strong>Penawaran:</strong> {{ $upgradeRequest->offer_name }}</p>
        <p><strong>Durasi snapshot:</strong> {{ $upgradeRequest->duration_months }} bulan</p>
        <p><strong>Jumlah snapshot:</strong> Rp{{ number_format($upgradeRequest->price_amount, 0, ',', '.') }} {{ $upgradeRequest->currency }}</p>
        <p><strong>Status:</strong> {{ $upgradeRequest->status->value }}</p>
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
            <p><strong>Subscription:</strong> window
                {{ $upgradeRequest->approvedSubscription?->starts_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
                –
                {{ $upgradeRequest->approvedSubscription?->ends_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}
            </p>
        @endif
    </div>

    @if ($upgradeRequest->status->value === 'pending')
        <div class="card">
            <div class="actions">
                <form method="POST" action="{{ route('admin.subscription-upgrades.approve', $upgradeRequest) }}">
                    @csrf
                    <button class="button" type="submit">Setujui</button>
                </form>
                <form method="POST" action="{{ route('admin.subscription-upgrades.cancel', $upgradeRequest) }}">
                    @csrf
                    <button class="button button-secondary" type="submit">Batalkan</button>
                </form>
            </div>

            <form method="POST" action="{{ route('admin.subscription-upgrades.reject', $upgradeRequest) }}" style="margin-top: 16px;">
                @csrf
                <label class="label" for="rejection_reason">Alasan penolakan</label>
                <textarea class="input" id="rejection_reason" name="rejection_reason" required>{{ old('rejection_reason') }}</textarea>
                @error('rejection_reason')
                    <p class="error-text">{{ $message }}</p>
                @enderror
                <button class="button button-danger" type="submit" style="margin-top: 12px;">Tolak</button>
            </form>
        </div>
    @endif
@endsection
