@extends('layouts.app')

@section('title', 'Verifikasi upgrade')

@section('content')
    <p class="muted">ADMIN</p>
    <h1>Verifikasi pembayaran upgrade</h1>

    <div class="actions" style="margin-bottom: 16px;">
        <a class="button button-secondary" href="{{ route('admin.subscription-upgrades.index', ['status' => 'pending']) }}">Pending</a>
        <a class="button button-secondary" href="{{ route('admin.subscription-upgrades.index', ['status' => 'approved']) }}">Approved</a>
        <a class="button button-secondary" href="{{ route('admin.subscription-upgrades.index', ['status' => 'rejected']) }}">Rejected</a>
        <a class="button button-secondary" href="{{ route('admin.subscription-upgrades.index', ['status' => 'cancelled']) }}">Cancelled</a>
        <a class="button button-secondary" href="{{ route('admin.subscription-upgrades.index', ['status' => 'all']) }}">Semua</a>
    </div>

    <div class="card">
        @if ($requests->isEmpty())
            <p>Tidak ada permintaan.</p>
        @else
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
                        <tr>
                            <td>
                                <a href="{{ route('admin.subscription-upgrades.show', $upgradeRequest) }}">
                                    {{ $upgradeRequest->reference_code }}
                                </a>
                            </td>
                            <td>{{ $upgradeRequest->user?->email }}</td>
                            <td>{{ $upgradeRequest->offer_name }}</td>
                            <td>Rp{{ number_format($upgradeRequest->price_amount, 0, ',', '.') }}</td>
                            <td>{{ $upgradeRequest->status->value }}</td>
                            <td class="muted">{{ $upgradeRequest->requested_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($requests->hasPages())
                <div class="actions" style="margin-top: 16px;">
                    @if ($requests->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <a class="button button-secondary" href="{{ $requests->previousPageUrl() }}">Sebelumnya</a>
                    @endif

                    @if ($requests->hasMorePages())
                        <a class="button button-secondary" href="{{ $requests->nextPageUrl() }}">Berikutnya</a>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endsection
