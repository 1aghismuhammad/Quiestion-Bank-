@extends('layouts.app')

@section('title', 'Langganan')

@section('content')
    @php
        $entitlement = $page->entitlement();
        $quota = $page->generationQuota;
        $usage = $page->generationUsage;
        $subscription = $entitlement->subscription;
    @endphp

    <p class="muted">SUBSCRIPTION</p>
    <h1>Paket langganan</h1>

    <div class="card" style="margin-bottom: 20px;">
        <h2>Paket saat ini</h2>
        <p><strong>{{ $entitlement->plan->name }}</strong></p>
        <p>
            <strong>Penyimpanan:</strong>
            {{ $page->storageUsedLabel() }} / {{ $page->storageLimitLabel() }}
        </p>
        <p>
            <strong>Kuota generation:</strong>
            @if ($quota->resetStrategy->value === 'lifetime')
                {{ $quota->limit }} seumur hidup
            @else
                {{ $quota->limit }} per jendela bulanan paket
            @endif
        </p>
        <p><strong>Terpakai:</strong> {{ $usage->consumed }}</p>
        <p><strong>Diproses:</strong> {{ $usage->reserved }}</p>
        <p><strong>Tersedia:</strong> {{ $usage->displayedAvailable() }}</p>
        @if ($entitlement->isPro() && $subscription)
            <p>
                <strong>Masa berlaku Pro:</strong>
                {{ $subscription->starts_at->timezone(config('app.timezone'))->format('d M Y H:i') }}
                –
                {{ $subscription->ends_at->timezone(config('app.timezone'))->format('d M Y H:i') }}
            </p>
            @if ($quota->windowStart && $quota->windowEnd)
                <p>
                    <strong>Jendela generation saat ini:</strong>
                    {{ $quota->windowStart->timezone(config('app.timezone'))->format('d M Y H:i') }}
                    –
                    {{ $quota->windowEnd->timezone(config('app.timezone'))->format('d M Y H:i') }}
                </p>
            @endif
        @endif
    </div>

    @if ($page->queuedRenewals->isNotEmpty())
        <div class="card" style="margin-bottom: 20px;">
            <h2>Perpanjangan terantre</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Mulai</th>
                        <th>Berakhir</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($page->queuedRenewals as $queued)
                        <tr>
                            <td>{{ $queued->starts_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td>{{ $queued->ends_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($page->pendingRequest)
        <div class="card" style="margin-bottom: 20px;">
            <h2>Permintaan tertunda</h2>
            <p><strong>Ref:</strong> {{ $page->pendingRequest->reference_code }}</p>
            <p><strong>Penawaran:</strong> {{ $page->pendingRequest->offer_name }}</p>
            <p><strong>Durasi:</strong> {{ $page->pendingRequest->duration_months }} bulan</p>
            <p><strong>Jumlah:</strong> Rp{{ number_format($page->pendingRequest->price_amount, 0, ',', '.') }}</p>
            <p><strong>Status:</strong> {{ $page->pendingRequest->status->value }}</p>
            @if ($page->whatsappConfigured)
                <form method="POST" action="{{ route('account.subscription.confirm') }}">
                    @csrf
                    <button class="button" type="submit">Konfirmasi Pembayaran via WhatsApp</button>
                </form>
            @else
                <p class="muted">Konfirmasi WhatsApp belum dikonfigurasi.</p>
            @endif
        </div>
    @endif

    <div class="card" style="margin-bottom: 20px;">
        <h2>Upgrade / Perpanjang</h2>

        @if ($page->pendingRequest)
            <p class="muted">Selesaikan atau tunggu verifikasi permintaan tertunda sebelum memilih paket lain.</p>
        @elseif (! $page->checkoutAvailable)
            @if ($page->qrisUrl === null)
                <p>QRIS belum dikonfigurasi.</p>
            @elseif (! $page->whatsappConfigured)
                <p>Konfirmasi WhatsApp belum dikonfigurasi.</p>
            @else
                <p>Paket berlangganan tidak tersedia saat ini.</p>
            @endif
        @else
            @if ($page->qrisUrl)
                <p class="muted">Scan QRIS di bawah, lalu konfirmasi pembayaran via WhatsApp.</p>
                <p>
                    <img src="{{ $page->qrisUrl }}" alt="QRIS pembayaran Pro" style="max-width: 280px; height: auto;">
                </p>
            @endif

            <div class="grid">
                @foreach ($page->offers as $offer)
                    <div class="card">
                        <h3>{{ $offer->name }}</h3>
                        <p><strong>Rp{{ number_format($offer->price_amount, 0, ',', '.') }}</strong></p>
                        <p class="muted">{{ $offer->duration_months }} bulan kalender</p>
                        <form method="POST" action="{{ route('account.subscription.confirm') }}">
                            @csrf
                            <input type="hidden" name="offer_id" value="{{ $offer->offer_id }}">
                            <button class="button" type="submit">Konfirmasi Pembayaran via WhatsApp</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($page->recentRequests->isNotEmpty())
        <div class="card">
            <h2>Riwayat permintaan</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Penawaran</th>
                        <th>Jumlah</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($page->recentRequests as $history)
                        <tr>
                            <td>{{ $history->reference_code }}</td>
                            <td>{{ $history->offer_name }}</td>
                            <td>Rp{{ number_format($history->price_amount, 0, ',', '.') }}</td>
                            <td>{{ $history->status->value }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
