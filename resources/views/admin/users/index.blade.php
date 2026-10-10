@php
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
@endphp

@extends('layouts.app')

@section('title', 'Manajemen pengguna')

@section('content')
    <div class="container" style="max-width: 72rem; margin-inline: auto; margin-top: 20px;">
        <x-ui.admin-nav />

        <div class="ui-page-header" style="margin-top: 24px; margin-bottom: 20px;">
            <h1>Manajemen pengguna</h1>
        </div>

        <x-ui.panel>
            <form method="GET" action="{{ route('admin.users.index') }}">
                <div class="grid">
                    <x-ui.text-input name="q" id="admin-user-search" label="Cari" :value="$filters['q']" placeholder="Nama, email, atau WhatsApp" />

                    <div>
                        <label class="label" for="admin-user-status">Status</label>
                        <select class="ui-input" id="admin-user-status" name="status">
                            <option value="">Semua status</option>
                            @foreach ($statusLabels as $value => $label)
                                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="admin-user-role">Peran</label>
                        <select class="ui-input" id="admin-user-role" name="role">
                            <option value="">Semua peran</option>
                            @foreach ($roleLabels as $value => $label)
                                <option value="{{ $value }}" @selected($filters['role'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="admin-user-plan">Paket</label>
                        <select class="ui-input" id="admin-user-plan" name="plan">
                            <option value="">Semua paket</option>
                            <option value="free" @selected($filters['plan'] === 'free')>Free</option>
                            <option value="pro" @selected($filters['plan'] === 'pro')>Pro</option>
                        </select>
                    </div>
                </div>

                <div class="action-stack" style="margin-top: 16px;">
                    <x-ui.button type="submit">Terapkan</x-ui.button>
                    <x-ui.button variant="secondary" href="{{ route('admin.users.index') }}">Reset</x-ui.button>
                </div>
            </form>
        </x-ui.panel>

        @if ($users->isEmpty())
            <x-ui.empty-state>Tidak ada pengguna.</x-ui.empty-state>
        @else
            <div class="responsive-table table-wrap" style="margin-top: 20px;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Email</th>
                            <th>WhatsApp</th>
                            <th>Peran</th>
                            <th>Status</th>
                            <th>Paket</th>
                            <th>Berlaku hingga</th>
                            <th>Dibuat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $account)
                            @php
                                $statusValue = $account->status->value;
                                $effective = $account->subscriptions->first();
                                $roleNames = $account->roles->pluck('role_name');
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.users.show', $account) }}" style="font-weight: 650; color: #5145dc; text-decoration: none;">{{ $account->name }}</a>
                                </td>
                                <td style="overflow-wrap: anywhere;">{{ $account->email }}</td>
                                <td>{{ $account->phone_number ?: '—' }}</td>
                                <td>{{ $roleNames->isEmpty() ? '—' : $roleNames->map(fn (string $role) => $roleLabels[$role] ?? $role)->implode(', ') }}</td>
                                <td>
                                    <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                        {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                                    </x-ui.status-badge>
                                </td>
                                <td>{{ $effective?->plan?->name ?? 'Free' }}</td>
                                <td class="muted">{{ $effective ? $effective->ends_at->timezone(config('app.timezone'))->format('d M Y H:i') : '—' }}</td>
                                <td class="muted">{{ $account->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="responsive-summary" style="margin-top: 20px;">
                @foreach ($users as $account)
                    @php
                        $statusValue = $account->status->value;
                        $effective = $account->subscriptions->first();
                        $roleNames = $account->roles->pluck('role_name');
                    @endphp
                    <article class="summary-row" style="padding: 16px; border-radius: 16px; border: 1px solid #ebe6dc; background: #ffffff;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                            <strong style="font-size: 1rem;"><a href="{{ route('admin.users.show', $account) }}" style="color: var(--color-text); text-decoration: none;">{{ $account->name }}</a></strong>
                            <x-ui.status-badge :variant="$statusVariants[$statusValue] ?? 'neutral'">
                                {{ $statusLabels[$statusValue] ?? 'Status tidak dikenali' }}
                            </x-ui.status-badge>
                        </div>
                        <p style="margin: 0; overflow-wrap: anywhere;">{{ $account->email }}</p>
                        <p class="muted" style="margin: 0;">WhatsApp: {{ $account->phone_number ?: '—' }}</p>
                        <p class="muted" style="margin: 0;">Peran: {{ $roleNames->isEmpty() ? '—' : $roleNames->map(fn (string $role) => $roleLabels[$role] ?? $role)->implode(', ') }}</p>
                        <p class="muted" style="margin: 0;">Paket: {{ $effective?->plan?->name ?? 'Free' }}@if ($effective) · hingga {{ $effective->ends_at->timezone(config('app.timezone'))->format('d M Y H:i') }}@endif</p>
                        <p class="muted" style="margin: 0;">Dibuat: {{ $account->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') ?: '—' }}</p>
                        <div style="margin-top: 8px;">
                            <x-ui.button variant="secondary" href="{{ route('admin.users.show', $account) }}" style="width: 100%;">Lihat detail</x-ui.button>
                        </div>
                    </article>
                @endforeach
            </div>

            @if ($users->hasPages())
                <div class="action-stack" style="margin-top: 20px;">
                    @if ($users->onFirstPage())
                        <span class="muted">Sebelumnya</span>
                    @else
                        <x-ui.button variant="secondary" href="{{ $users->previousPageUrl() }}">Sebelumnya</x-ui.button>
                    @endif

                    @if ($users->hasMorePages())
                        <x-ui.button variant="secondary" href="{{ $users->nextPageUrl() }}">Berikutnya</x-ui.button>
                    @else
                        <span class="muted">Berikutnya</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
@endsection
