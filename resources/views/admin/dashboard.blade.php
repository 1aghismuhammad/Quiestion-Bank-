@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
    <p class="muted">ADMIN DASHBOARD</p>
    <h1>Ringkasan pengguna</h1>
    <p class="muted">Monitoring detail belum termasuk dalam Phase 1.</p>

    <p>
        <a class="button" href="{{ route('materials.index') }}">Materi saya</a>
        <a class="button" href="{{ route('admin.subscription-upgrades.index') }}">Verifikasi upgrade</a>
    </p>

    <div class="grid" style="margin-top: 24px;">
        <div class="card">
            <span class="muted">Total User</span>
            <p class="stat">{{ $totalUsers }}</p>
        </div>

        <div class="card">
            <span class="muted">Total Admin</span>
            <p class="stat">{{ $totalAdmins }}</p>
        </div>
    </div>
@endsection
