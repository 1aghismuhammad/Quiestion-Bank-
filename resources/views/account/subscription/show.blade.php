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
        $dateFormat = 'd M Y H:i';
    @endphp

    <div class="subscription-page">
        <x-ui.page-header>
            Langganan
        </x-ui.page-header>

        <x-ui.panel class="subscription-current-plan" role="region" aria-labelledby="subscription-current-title">
            <div class="subscription-plan-head">
                <h2 id="subscription-current-title">Paket saat ini</h2>
                <p class="subscription-plan-name"><strong>{{ $entitlement->plan->name }}</strong></p>
            </div>

            <dl class="subscription-facts">
                <div>
                    <dt><strong>Penyimpanan:</strong></dt>
                    <dd>{{ $page->storageUsedLabel() }} / {{ $page->storageLimitLabel() }}</dd>
                </div>
                <div>
                    <dt><strong>Kuota pembuatan soal:</strong></dt>
                    <dd>
                        @if ($quota->resetStrategy->value === 'lifetime')
                            {{ $quota->limit }} seumur hidup
                        @else
                            {{ $quota->limit }} per jendela bulanan paket
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="subscription-usage-grid">
                <p class="subscription-stat"><strong>Terpakai:</strong> {{ $usage->consumed }}</p>
                <p class="subscription-stat"><strong>Diproses:</strong> {{ $usage->reserved }}</p>
                <p class="subscription-stat"><strong>Tersedia:</strong> {{ $usage->displayedAvailable() }}</p>
            </div>

            @if ($entitlement->isPro() && $subscription)
                <dl class="subscription-dates">
                    <div>
                        <dt><strong>Masa berlaku Pro:</strong></dt>
                        <dd>
                            {{ $subscription->starts_at->timezone(config('app.timezone'))->format($dateFormat) }}
                            –
                            {{ $subscription->ends_at->timezone(config('app.timezone'))->format($dateFormat) }}
                        </dd>
                    </div>
                    @if ($quota->windowStart && $quota->windowEnd)
                        <div>
                            <dt><strong>Jendela pembuatan soal saat ini:</strong></dt>
                            <dd>
                                {{ $quota->windowStart->timezone(config('app.timezone'))->format($dateFormat) }}
                                –
                                {{ $quota->windowEnd->timezone(config('app.timezone'))->format($dateFormat) }}
                            </dd>
                        </div>
                    @endif
                </dl>
            @endif
        </x-ui.panel>

        @if ($page->queuedRenewals->isNotEmpty())
            <x-ui.panel class="subscription-queued" role="region" aria-labelledby="subscription-queued-title">
                <h2 id="subscription-queued-title">Perpanjangan terantre</h2>

                <div class="responsive-table table-wrap">
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
                                    <td>{{ $queued->starts_at->timezone(config('app.timezone'))->format($dateFormat) }}</td>
                                    <td>{{ $queued->ends_at->timezone(config('app.timezone'))->format($dateFormat) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="responsive-summary">
                    @foreach ($page->queuedRenewals as $queued)
                        <dl class="subscription-record">
                            <div>
                                <dt>Mulai</dt>
                                <dd>{{ $queued->starts_at->timezone(config('app.timezone'))->format($dateFormat) }}</dd>
                            </div>
                            <div>
                                <dt>Berakhir</dt>
                                <dd>{{ $queued->ends_at->timezone(config('app.timezone'))->format($dateFormat) }}</dd>
                            </div>
                        </dl>
                    @endforeach
                </div>
            </x-ui.panel>
        @endif

        @if ($page->pendingRequest)
            <x-ui.panel class="subscription-pending-card" role="region" aria-labelledby="subscription-pending-title">
                <h2 id="subscription-pending-title">Permintaan tertunda</h2>

                <dl class="subscription-pending-details">
                    <div>
                        <dt><strong>Referensi:</strong></dt>
                        <dd class="subscription-reference">{{ $page->pendingRequest->reference_code }}</dd>
                    </div>
                    <div>
                        <dt><strong>Penawaran:</strong></dt>
                        <dd>{{ $page->pendingRequest->offer_name }}</dd>
                    </div>
                    <div>
                        <dt><strong>Durasi:</strong></dt>
                        <dd>{{ $page->pendingRequest->duration_months }} bulan</dd>
                    </div>
                    <div>
                        <dt><strong>Jumlah:</strong></dt>
                        <dd>Rp{{ number_format($page->pendingRequest->price_amount, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt><strong>Status:</strong></dt>
                        <dd>
                            <x-ui.status-badge variant="processing">
                                {{ $upgradeStatusLabels[$page->pendingRequest->status->value] ?? 'Status tidak dikenali' }}
                            </x-ui.status-badge>
                        </dd>
                    </div>
                </dl>

                @if ($page->whatsappConfigured)
                    <form class="subscription-pending-action" method="POST" action="{{ route('account.subscription.confirm') }}">
                        @csrf
                        <x-ui.button type="submit">Konfirmasi pembayaran</x-ui.button>
                    </form>
                    <p class="muted subscription-pending-note">Konfirmasi pembayaran melalui WhatsApp, lalu tunggu proses verifikasi.</p>
                @else
                    <p class="muted">Konfirmasi WhatsApp belum dikonfigurasi.</p>
                @endif
            </x-ui.panel>
        @endif

        <section class="subscription-upgrade" aria-labelledby="subscription-upgrade-title">
            <h2 id="subscription-upgrade-title">Upgrade / Perpanjang</h2>

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
                <div @class(['subscription-checkout', 'subscription-checkout--with-qris' => (bool) $page->qrisUrl])>
                    @if ($page->qrisUrl)
                        <div class="subscription-qris-block">
                            <p class="muted">Scan QRIS di bawah, lalu konfirmasi pembayaran via WhatsApp.</p>
                            <div class="subscription-qris">
                                <img src="{{ $page->qrisUrl }}" alt="QRIS pembayaran Pro">
                            </div>
                        </div>
                    @endif

                    <div class="subscription-offers-grid">
                        @foreach ($page->offers as $offer)
                            <x-ui.panel class="subscription-offer-card">
                                <h3>{{ $offer->name }}</h3>
                                <p class="subscription-offer-price"><strong>Rp{{ number_format($offer->price_amount, 0, ',', '.') }}</strong></p>
                                <p class="muted">{{ $offer->duration_months }} bulan kalender</p>
                                <form method="POST" action="{{ route('account.subscription.confirm') }}">
                                    @csrf
                                    <input type="hidden" name="offer_id" value="{{ $offer->offer_id }}">
                                    <x-ui.button variant="{{ $offerButtonVariant }}" type="submit">Konfirmasi pembayaran</x-ui.button>
                                </form>
                            </x-ui.panel>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>

        @if ($page->recentRequests->isNotEmpty())
            <x-ui.panel class="subscription-history" role="region" aria-labelledby="subscription-history-title">
                <h2 id="subscription-history-title">Riwayat permintaan</h2>

                <div class="responsive-table table-wrap">
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
                                    <td class="subscription-reference">{{ $history->reference_code }}</td>
                                    <td>{{ $history->offer_name }}</td>
                                    <td>Rp{{ number_format($history->price_amount, 0, ',', '.') }}</td>
                                    <td>{{ $upgradeStatusLabels[$history->status->value] ?? 'Status tidak dikenali' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="responsive-summary">
                    @foreach ($page->recentRequests as $history)
                        <dl class="subscription-record">
                            <div>
                                <dt>Referensi</dt>
                                <dd class="subscription-reference">{{ $history->reference_code }}</dd>
                            </div>
                            <div>
                                <dt>Penawaran</dt>
                                <dd>{{ $history->offer_name }}</dd>
                            </div>
                            <div>
                                <dt>Jumlah</dt>
                                <dd>Rp{{ number_format($history->price_amount, 0, ',', '.') }}</dd>
                            </div>
                            <div>
                                <dt>Status</dt>
                                <dd>{{ $upgradeStatusLabels[$history->status->value] ?? 'Status tidak dikenali' }}</dd>
                            </div>
                        </dl>
                    @endforeach
                </div>
            </x-ui.panel>
        @endif
    </div>
@endsection
