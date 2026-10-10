@extends('layouts.app')

@section('title', 'Dasbor admin')

@section('content')
    <div class="container" style="max-width: 60rem; margin-inline: auto; margin-top: 20px;">
        <x-ui.admin-nav />

        <div class="ui-page-header" style="margin-top: 24px;">
            <h1>Dasbor admin</h1>
        </div>

        <div class="grid" style="margin-bottom: 24px;">
            <div class="card" style="padding: 20px;">
                <p class="label muted" style="margin:0;">Jumlah pengguna: {{ $totalUsers }}</p>
                <p class="stat" style="margin: 4px 0 0; font-size: 2rem;">{{ $totalUsers }}</p>
            </div>
            <div class="card" style="padding: 20px;">
                <p class="label muted" style="margin:0;">Jumlah admin: {{ $totalAdmins }}</p>
                <p class="stat" style="margin: 4px 0 0; font-size: 2rem;">{{ $totalAdmins }}</p>
            </div>
        </div>

        <div class="card" style="padding: 20px;">
            <h2 style="margin: 0 0 16px; font-size: 1.125rem;">Tindakan Cepat</h2>
            <div class="action-stack">
                <x-ui.button href="{{ route('admin.subscription-upgrades.index', ['status' => 'pending']) }}">Verifikasi pembayaran</x-ui.button>
                <x-ui.button variant="secondary" href="{{ route('admin.users.index') }}">Manajemen pengguna</x-ui.button>
                <x-ui.button variant="secondary" href="{{ route('dashboard') }}">Kembali ke dasbor</x-ui.button>
            </div>
        </div>
    </div>
@endsection
