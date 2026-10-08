@extends('layouts.app')

@section('title', 'Dasbor admin')

@section('content')
    <x-ui.page-header>
        Dasbor admin
    </x-ui.page-header>

    <p>Jumlah pengguna: {{ $totalUsers }}</p>
    <p>Jumlah admin: {{ $totalAdmins }}</p>

    <div class="action-stack">
        <x-ui.button href="{{ route('admin.subscription-upgrades.index', ['status' => 'pending']) }}">Verifikasi pembayaran</x-ui.button>
        <x-ui.button variant="secondary" href="{{ route('admin.users.index') }}">Manajemen pengguna</x-ui.button>
        <x-ui.button variant="secondary" href="{{ route('dashboard') }}">Kembali ke dasbor</x-ui.button>
    </div>
@endsection
