@php
    use App\Enums\UserStatus;

    $statusLabels = [
        'active' => 'Aktif',
        'suspended' => 'Ditangguhkan',
        'inactive' => 'Nonaktif',
    ];
    $statusVariants = [
        'active' => 'success',
        'suspended' => 'warning',
        'inactive' => 'neutral',
    ];
    $roleLabels = [
        'ADMIN' => 'Admin',
        'USER' => 'Pengguna',
    ];
    $subscriptionStatusLabels = [
        'active' => 'Aktif',
        'expired' => 'Kedaluwarsa',
        'cancelled' => 'Dibatalkan',
    ];
    $accountStatus = $account->status->value;
    $roleNames = $account->roles->pluck('role_name');
    $timezone = config('app.timezone');
    $format = 'd M Y H:i';
    $subscription = $entitlement->subscription;
@endphp

@extends('layouts.app')

@section('title', $account->name)

@section('content')
    <x-ui.page-header>
        {{ $account->name }}
        <x-slot:back>
            <x-ui.button variant="tertiary" href="{{ route('admin.users.index') }}">Kembali ke daftar pengguna</x-ui.button>
        </x-slot:back>
        <x-slot:status>
            <x-ui.status-badge :variant="$statusVariants[$accountStatus] ?? 'neutral'">
                {{ $statusLabels[$accountStatus] ?? 'Status tidak dikenali' }}
            </x-ui.status-badge>
        </x-slot:status>
    </x-ui.page-header>

    <x-ui.panel>
        <h2>Identitas</h2>
        @if ($account->avatar_url)
            <p><img src="{{ $account->avatar_url }}" alt="" width="64" height="64"></p>
        @endif
        <p><strong>Nama:</strong> {{ $account->name }}</p>
        <p><strong>Email:</strong> {{ $account->email }}</p>
        <p><strong>Google ID:</strong> {{ $account->google_id ?: '—' }}</p>
        <p><strong>WhatsApp:</strong> {{ $account->phone_number ?: '—' }}</p>
        <p><strong>Dibuat:</strong> {{ $account->created_at?->timezone($timezone)->format($format) }}</p>
        <p><strong>Login terakhir:</strong> {{ $account->last_login_at?->timezone($timezone)->format($format) ?: '—' }}</p>
    </x-ui.panel>

    <x-ui.panel>
        <h2>Akun</h2>
        <p><strong>Status:</strong> {{ $statusLabels[$accountStatus] ?? 'Status tidak dikenali' }}</p>
        <p><strong>Peran:</strong> {{ $roleNames->isEmpty() ? '—' : $roleNames->map(fn (string $role) => $roleLabels[$role] ?? $role)->implode(', ') }}</p>

        @can('update', $account)
            <form method="POST" action="{{ route('admin.users.update', $account) }}">
                @csrf
                @method('PATCH')
                <label class="label" for="account-status">Status akun</label>
                <select class="ui-input" id="account-status" name="status" @error('status') aria-invalid="true" aria-describedby="account-status-error" @enderror>
                    <option value="{{ UserStatus::ACTIVE->value }}" @selected(old('status', $accountStatus) === UserStatus::ACTIVE->value)>Aktif</option>
                    <option value="{{ UserStatus::INACTIVE->value }}" @selected(old('status', $accountStatus) === UserStatus::INACTIVE->value)>Nonaktif</option>
                </select>
                @error('status')
                    <div class="error-text" id="account-status-error">{{ $message }}</div>
                @enderror
                <div class="action-stack" style="margin-top: 16px;">
                    <x-ui.button type="submit">Simpan status</x-ui.button>
                </div>
            </form>
        @elseif ($account->status === UserStatus::SUSPENDED)
            <p class="muted">Status ditangguhkan hanya ditampilkan. Perubahan status tidak tersedia.</p>
        @endif
    </x-ui.panel>

    <x-ui.panel>
        <h2>Paket saat ini</h2>
        <p><strong>{{ $entitlement->plan->name }}</strong></p>
        @if ($entitlement->isPro() && $subscription)
            <p>
                <strong>Masa berlaku:</strong>
                {{ $subscription->starts_at->timezone($timezone)->format($format) }}
                –
                {{ $subscription->ends_at->timezone($timezone)->format($format) }}
            </p>
        @endif
        <p><strong>Penyimpanan:</strong> {{ $storageUsedLabel }} / {{ $storageLimitLabel }}</p>
        <p><strong>Kuota pembuatan soal:</strong> {{ $quotaLabel }}</p>
        <p><strong>Terpakai:</strong> {{ $usage->consumed }}</p>
        <p><strong>Diproses:</strong> {{ $usage->reserved }}</p>
        <p><strong>Tersedia:</strong> {{ $usage->displayedAvailable() }}</p>
        @if ($windowLabel)
            <p><strong>Jendela pembuatan soal saat ini:</strong> {{ $windowLabel }}</p>
        @endif
    </x-ui.panel>

    <x-ui.panel>
        <h2>Riwayat langganan</h2>
        @if ($subscriptions->isEmpty())
            <p class="muted">Belum ada langganan.</p>
        @else
            <div class="responsive-table table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Paket</th>
                            <th>Status</th>
                            <th>Mulai</th>
                            <th>Berakhir</th>
                            <th>Dibatalkan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subscriptions as $row)
                            <tr>
                                <td>{{ $row->plan?->name ?? '—' }}</td>
                                <td>{{ $subscriptionStatusLabels[$row->status->value] ?? 'Status tidak dikenali' }}</td>
                                <td>{{ $row->starts_at->timezone($timezone)->format($format) }}</td>
                                <td>{{ $row->ends_at->timezone($timezone)->format($format) }}</td>
                                <td>{{ $row->cancelled_at?->timezone($timezone)->format($format) ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.panel>
@endsection
