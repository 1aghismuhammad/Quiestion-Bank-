@php
    use App\Enums\PlanCode;
    use App\Enums\SubscriptionStatus;
    use App\Enums\UserStatus;
    use Illuminate\Support\Str;

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
    <div class="container" style="max-width: 64rem; margin-inline: auto; margin-top: 20px;">
        <x-ui.admin-nav />

        <div class="ui-page-header" style="margin-top: 24px;">
            <div>
                <h1 style="display: flex; align-items: center; gap: 12px; margin: 0;">
                    {{ $account->name }}
                    <x-ui.status-badge :variant="$statusVariants[$accountStatus] ?? 'neutral'">
                        {{ $statusLabels[$accountStatus] ?? 'Status tidak dikenali' }}
                    </x-ui.status-badge>
                </h1>
            </div>
            <div class="ui-page-header-actions">
                <x-ui.button variant="tertiary" href="{{ route('admin.users.index') }}">Kembali ke daftar pengguna</x-ui.button>
            </div>
        </div>

        <div class="grid" style="margin-bottom: 24px; align-items: stretch;">
            <div class="card" style="padding: 24px;">
                <h2 style="font-size: 1.125rem; margin: 0 0 16px;">Identitas</h2>
                @if ($account->avatar_url)
                    <p style="margin: 0 0 16px;"><img src="{{ $account->avatar_url }}" alt="" width="64" height="64" style="border-radius: 999px;"></p>
                @endif
                <div style="display: grid; gap: 8px;">
                    <p style="margin:0; overflow-wrap: anywhere;"><strong class="muted">Nama:</strong><br>{{ $account->name }}</p>
                    <p style="margin:0; overflow-wrap: anywhere;"><strong class="muted">Email:</strong><br>{{ $account->email }}</p>
                    <p style="margin:0; overflow-wrap: anywhere;"><strong class="muted">Google ID:</strong><br>{{ $account->google_id ?: '—' }}</p>
                    <p style="margin:0; overflow-wrap: anywhere;"><strong class="muted">WhatsApp:</strong><br>{{ $account->phone_number ?: '—' }}</p>
                    <p style="margin:0;"><strong class="muted">Dibuat:</strong><br>{{ $account->created_at?->timezone($timezone)->format($format) }}</p>
                    <p style="margin:0;"><strong class="muted">Login terakhir:</strong><br>{{ $account->last_login_at?->timezone($timezone)->format($format) ?: '—' }}</p>
                </div>
            </div>

            <div class="card" style="padding: 24px;">
                <h2 style="font-size: 1.125rem; margin: 0 0 16px;">Akun</h2>
                <div style="display: grid; gap: 8px; margin-bottom: 20px;">
                    <p style="margin:0;"><strong class="muted">Status:</strong><br>{{ $statusLabels[$accountStatus] ?? 'Status tidak dikenali' }}</p>
                    <p style="margin:0;"><strong class="muted">Peran:</strong><br>{{ $roleNames->isEmpty() ? '—' : $roleNames->map(fn (string $role) => $roleLabels[$role] ?? $role)->implode(', ') }}</p>
                </div>

                @can('update', $account)
                    <form method="POST" action="{{ route('admin.users.update', $account) }}" style="background: #fbfaf7; border: 1px solid #ebe6dc; border-radius: 12px; padding: 16px;">
                        @csrf
                        @method('PATCH')
                        <label class="label" for="account-status">Status akun</label>
                        <select class="ui-input" id="account-status" name="status" @error('status') aria-invalid="true" aria-describedby="account-status-error" @enderror style="width: 100%; margin-bottom: 12px;">
                            <option value="{{ UserStatus::ACTIVE->value }}" @selected(old('status', $accountStatus) === UserStatus::ACTIVE->value)>Aktif</option>
                            <option value="{{ UserStatus::INACTIVE->value }}" @selected(old('status', $accountStatus) === UserStatus::INACTIVE->value)>Nonaktif</option>
                        </select>
                        @error('status')
                            <div class="error-text" id="account-status-error" style="margin-bottom: 12px;">{{ $message }}</div>
                        @enderror
                        <div class="action-stack">
                            <x-ui.button type="submit" variant="secondary" style="width: 100%;">Simpan status</x-ui.button>
                        </div>
                    </form>
                @elseif ($account->status === UserStatus::SUSPENDED)
                    <div style="background: #fff4dc; border: 1px solid #ffe8b5; padding: 12px; border-radius: 8px;">
                        <p class="muted" style="margin: 0; color: #7a5200;">Status ditangguhkan hanya ditampilkan. Perubahan status tidak tersedia.</p>
                    </div>
                @endif
            </div>
        </div>

        <div class="grid" style="margin-bottom: 24px; align-items: stretch;">
            <div class="card" style="padding: 24px;">
                <h2 style="font-size: 1.125rem; margin: 0 0 16px;">Paket saat ini</h2>
                <div style="display: grid; gap: 12px; margin-bottom: 20px;">
                    <div>
                        <strong class="muted" style="font-size: 0.875rem;">Paket</strong>
                        <p style="margin: 2px 0 0; font-size: 1.125rem; font-weight: 650; color: #5145dc;">{{ $entitlement->plan->name }}</p>
                    </div>
                    @if ($entitlement->isPro() && $subscription)
                        <div>
                            <strong class="muted" style="font-size: 0.875rem;">Masa berlaku:</strong>
                            <p style="margin: 2px 0 0; overflow-wrap: anywhere;">
                                {{ $subscription->starts_at->timezone($timezone)->format($format) }}<br>
                                – {{ $subscription->ends_at->timezone($timezone)->format($format) }}
                            </p>
                        </div>
                    @endif
                </div>

                <div style="display: grid; gap: 8px; padding: 16px; background: #fbfaf7; border: 1px solid #ebe6dc; border-radius: 12px;">
                    <p style="margin:0;"><strong class="muted">Penyimpanan:</strong><br>{{ $storageUsedLabel }} / {{ $storageLimitLabel }}</p>
                    <p style="margin:0; margin-top: 8px;"><strong class="muted">Kuota pembuatan soal:</strong><br>{{ $quotaLabel }}</p>
                    <div style="display: flex; flex-wrap: wrap; gap: 16px; margin-top: 4px;">
                        <p style="margin:0;"><strong class="muted">Terpakai:</strong> {{ $usage->consumed }}</p>
                        <p style="margin:0;"><strong class="muted">Diproses:</strong> {{ $usage->reserved }}</p>
                        <p style="margin:0;"><strong class="muted">Tersedia:</strong> <span style="font-weight: 650;">{{ $usage->displayedAvailable() }}</span></p>
                    </div>
                    @if ($windowLabel)
                        <p style="margin:0; margin-top: 8px;"><strong class="muted">Jendela pembuatan soal saat ini:</strong><br>{{ $windowLabel }}</p>
                    @endif
                </div>
            </div>

            @can('manageSubscription', $account)
                <div class="card" style="padding: 24px; display: flex; flex-direction: column;">
                    <h2 style="font-size: 1.125rem; margin: 0 0 16px;">Kelola Langganan</h2>
                    @if ($pendingUpgrade)
                        <div style="background: #fffaf0; border: 1px solid #efdcae; padding: 16px; border-radius: 12px; margin-bottom: 16px;">
                            <p style="margin: 0 0 12px; font-size: 0.9375rem;">Pengguna memiliki permintaan upgrade yang masih tertunda. Selesaikan permintaan tersebut sebelum mengelola langganan secara langsung.</p>
                            <x-ui.button variant="secondary" href="{{ route('admin.subscription-upgrades.show', $pendingUpgrade) }}" style="width: 100%;">Lihat permintaan</x-ui.button>
                        </div>
                    @elseif ($account->status === UserStatus::ACTIVE)
                        @if ($hasCurrentOrFuturePro)
                            <div style="background: #e8f0f7; border: 1px solid #cdddea; padding: 12px; border-radius: 12px; margin-bottom: 16px;">
                                <p class="muted" style="margin: 0; color: #1e4a73; font-size: 0.875rem;">Langganan baru akan dimulai setelah masa Pro terakhir berakhir.</p>
                            </div>
                        @endif
                        <form method="POST" action="{{ route('admin.users.subscriptions.store', $account) }}" style="display: flex; flex-direction: column; flex-grow: 1;">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}">

                            <div style="margin-bottom: 12px;">
                                <label class="label" for="duration-months">Durasi</label>
                                <select class="ui-input" id="duration-months" name="duration_months" style="width: 100%;">
                                    <option value="1">1 bulan</option>
                                    <option value="3">3 bulan</option>
                                    <option value="6">6 bulan</option>
                                    <option value="12">12 bulan</option>
                                </select>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label class="label" for="grant-reason">Alasan</label>
                                <textarea class="ui-input" id="grant-reason" name="reason" required maxlength="1000" style="width: 100%; min-height: 4rem;"></textarea>
                            </div>

                            <div class="action-stack" style="margin-top: auto;">
                                <x-ui.button type="submit" style="width: 100%;">{{ $hasCurrentOrFuturePro ? 'Tambah masa langganan' : 'Berikan Pro' }}</x-ui.button>
                            </div>
                        </form>
                    @else
                        <div style="background: #f6f5f2; padding: 16px; border-radius: 12px; margin-top: auto;">
                            <p class="muted" style="margin: 0;">Pemberian langganan hanya tersedia untuk akun aktif.</p>
                        </div>
                    @endif
                </div>
            @endcan
        </div>

        <div class="card" style="padding: 24px; margin-bottom: 40px;">
            <h2 style="font-size: 1.125rem; margin: 0 0 16px;">Riwayat langganan</h2>
            @if ($subscriptions->isEmpty())
                <p class="muted" style="margin: 0;">Belum ada langganan.</p>
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
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subscriptions as $row)
                                <tr>
                                    <td>{{ $row->plan?->name ?? '—' }}</td>
                                    <td>
                                        <x-ui.status-badge :variant="$row->status->value === 'active' ? 'success' : ($row->status->value === 'cancelled' ? 'danger' : 'neutral')">
                                            {{ $subscriptionStatusLabels[$row->status->value] ?? 'Status tidak dikenali' }}
                                        </x-ui.status-badge>
                                    </td>
                                    <td>{{ $row->starts_at->timezone($timezone)->format($format) }}</td>
                                    <td>{{ $row->ends_at->timezone($timezone)->format($format) }}</td>
                                    <td class="muted">{{ $row->cancelled_at?->timezone($timezone)->format($format) ?: '—' }}</td>
                                    <td>
                                        @php
                                            $canCancelRow = $row->status === SubscriptionStatus::ACTIVE
                                                && $row->plan?->code === PlanCode::PRO
                                                && $row->ends_at->gt(now());
                                        @endphp
                                        @can('manageSubscription', $account)
                                            @if ($canCancelRow && ! $pendingUpgrade)
                                                <form method="POST" action="{{ route('admin.users.subscriptions.destroy', [$account, $row]) }}" onsubmit="return confirm('Batalkan langganan ini?')" style="display: grid; gap: 8px;">
                                                    @csrf
                                                    @method('DELETE')
                                                    <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}">
                                                    <label class="label" for="cancel-reason-{{ $row->subscription_id }}-desktop" style="font-size: 0.8125rem; margin-bottom: 0;">Alasan pembatalan</label>
                                                    <input type="text" class="ui-input" id="cancel-reason-{{ $row->subscription_id }}-desktop" name="reason" placeholder="Alasan pembatalan..." required maxlength="1000" style="min-height: 44px; padding: 8px 12px;">
                                                    <x-ui.button variant="danger" type="submit" style="min-height: 44px; width: 100%;">Batalkan</x-ui.button>
                                                </form>
                                            @else
                                                <span class="muted">—</span>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="responsive-summary">
                    @foreach ($subscriptions as $row)
                        @php
                            $canCancelRow = $row->status === SubscriptionStatus::ACTIVE
                                && $row->plan?->code === PlanCode::PRO
                                && $row->ends_at->gt(now());
                        @endphp
                        <article class="summary-row" style="padding: 16px; border-radius: 16px; border: 1px solid #ebe6dc; background: #ffffff;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                                <strong style="font-size: 1rem;">{{ $row->plan?->name ?? '—' }}</strong>
                                <x-ui.status-badge :variant="$row->status->value === 'active' ? 'success' : ($row->status->value === 'cancelled' ? 'danger' : 'neutral')">
                                    {{ $subscriptionStatusLabels[$row->status->value] ?? 'Status tidak dikenali' }}
                                </x-ui.status-badge>
                            </div>
                            <div style="display: grid; gap: 4px; margin-top: 8px;">
                                <p class="muted" style="margin: 0;"><strong>Mulai:</strong> {{ $row->starts_at->timezone($timezone)->format($format) }}</p>
                                <p class="muted" style="margin: 0;"><strong>Berakhir:</strong> {{ $row->ends_at->timezone($timezone)->format($format) }}</p>
                                @if ($row->cancelled_at)
                                    <p class="muted" style="margin: 0; color: #9f2424;"><strong>Dibatalkan:</strong> {{ $row->cancelled_at->timezone($timezone)->format($format) }}</p>
                                @endif
                            </div>
                            @can('manageSubscription', $account)
                                @if ($canCancelRow && ! $pendingUpgrade)
                                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0ede6;">
                                        <form method="POST" action="{{ route('admin.users.subscriptions.destroy', [$account, $row]) }}" onsubmit="return confirm('Batalkan langganan ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="idempotency_key" value="{{ (string) Str::uuid() }}">
                                            <label class="label" for="cancel-reason-{{ $row->subscription_id }}-mobile" style="font-size: 0.8125rem;">Alasan pembatalan</label>
                                            <textarea class="ui-input" id="cancel-reason-{{ $row->subscription_id }}-mobile" name="reason" required maxlength="1000" style="width: 100%; min-height: 3rem; margin-bottom: 8px;"></textarea>
                                            <x-ui.button variant="danger" type="submit" style="width: 100%;">Batalkan langganan</x-ui.button>
                                        </form>
                                    </div>
                                @endif
                            @endcan
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
