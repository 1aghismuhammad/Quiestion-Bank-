@extends('layouts.app')

@section('title', 'Langganan')

@section('content')
    @php
        $entitlement = $page->entitlement();
        $quota = $page->generationQuota;
        $usage = $page->generationUsage;
        $subscription = $entitlement->subscription;
        $upgradeStatusLabels = [
            'pending' => 'Tertunda',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'cancelled' => 'Dibatalkan',
        ];
        $offerCount = $page->offers->count();
        $offerButtonVariant = $offerCount === 1 ? 'primary' : 'secondary';
    @endphp

    <x-ui.page-header>
        Langganan
    </x-ui.page-header>

    <x-ui.panel>
        <h2>Paket saat ini</h2>
        <p><strong>{{ $entitlement->plan->name }}</strong></p>
        <p>
            <strong>Penyimpanan:</strong>
            {{ $page->storageUsedLabel() }} / {{ $page->storageLimitLabel() }}
        </p>
        <p>
            <strong>Kuota pembuatan soal:</strong>
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
                    <strong>Jendela pembuatan soal saat ini:</strong>
                    {{ $quota->windowStart->timezone(config('app.timezone'))->format('d M Y H:i') }}
                    –
                    {{ $quota->windowEnd->timezone(config('app.timezone'))->format('d M Y H:i') }}
                </p>
            @endif
        @endif
    </x-ui.panel>

    @if ($page->queuedRenewals->isNotEmpty())
        <x-ui.panel>
            <h2>Perpanjangan terantre</h2>
            <div class="table-wrap">
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
        </x-ui.panel>
    @endif

    @if ($page->pendingRequest)
        <x-ui.panel>
            <h2>Permintaan tertunda</h2>
            <p><strong>Referensi:</strong> {{ $page->pendingRequest->reference_code }}</p>
            <p><strong>Penawaran:</strong> {{ $page->pendingRequest->offer_name }}</p>
            <p><strong>Durasi:</strong> {{ $page->pendingRequest->duration_months }} bulan</p>
            <p><strong>Jumlah:</strong> Rp{{ number_format($page->pendingRequest->price_amount, 0, ',', '.') }}</p>
            <p>
                <strong>Status:</strong>
                <x-ui.status-badge variant="processing">
                    {{ $upgradeStatusLabels[$page->pendingRequest->status->value] ?? 'Status tidak dikenali' }}
                </x-ui.status-badge>
            </p>
            @if ($page->whatsappConfigured)
                <form method="POST" action="{{ route('account.subscription.confirm') }}">
                    @csrf
                    <x-ui.button type="submit">Konfirmasi pembayaran</x-ui.button>
                </form>
            @else
                <p class="muted">Konfirmasi WhatsApp belum dikonfigurasi.</p>
            @endif
        </x-ui.panel>
    @endif

    <h2>Upgrade / Perpanjang</h2>

    @if ($page->pendingRequest)
        <p class="muted">Selesaikan atau tunggu verifikasi permintaan tertunda sebelum memilih paket lain.</p>
    @elseif (! $page->checkoutAvailable)
        @if ($page->qrisUrl === null)
            <x-ui.alert>QRIS belum dikonfigurasi.</x-ui.alert>
        @elseif (! $page->whatsappConfigured)
            <x-ui.alert>Konfirmasi WhatsApp belum dikonfigurasi.</x-ui.alert>
        @else
            <x-ui.alert>Paket berlangganan tidak tersedia saat ini.</x-ui.alert>
        @endif
    @else
        @if ($page->qrisUrl)
            <p class="muted">Scan QRIS di bawah, lalu konfirmasi pembayaran via WhatsApp.</p>
            <p>
                <img src="{{ $page->qrisUrl }}" alt="QRIS pembayaran Pro" style="max-width: min(280px, 100%); height: auto;">
            </p>
        @endif

        @foreach ($page->offers as $offer)
            <x-ui.panel>
                <h3>{{ $offer->name }}</h3>
                <p><strong>Rp{{ number_format($offer->price_amount, 0, ',', '.') }}</strong></p>
                <p class="muted">{{ $offer->duration_months }} bulan kalender</p>
                <form method="POST" action="{{ route('account.subscription.confirm') }}">
                    @csrf
                    <input type="hidden" name="offer_id" value="{{ $offer->offer_id }}">
                    <x-ui.button variant="{{ $offerButtonVariant }}" type="submit">Konfirmasi pembayaran</x-ui.button>
                </form>
            </x-ui.panel>
        @endforeach
    @endif

    @if ($page->recentRequests->isNotEmpty())
        <x-ui.panel>
            <h2>Riwayat permintaan</h2>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Referensi</th>
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
                                <td>{{ $upgradeStatusLabels[$history->status->value] ?? 'Status tidak dikenali' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.panel>
    @endif
@endsection
