@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="card" style="margin-bottom: 20px;">
        <p class="muted">USER DASHBOARD</p>
        <h1>Selamat datang, {{ $user->name }}</h1>
        <p><strong>Email:</strong> {{ $user->email }}</p>
        <p>
            <strong>Status akun:</strong>
            <span class="status">{{ strtoupper($user->status->value) }}</span>
        </p>
    </div>

    <h2>Menu</h2>
    <div class="grid">
        @foreach (['Generate Question', 'Question Bank', 'History', 'Subscription'] as $menu)
            <div class="card placeholder">
                <strong>{{ $menu }}</strong>
                <span class="muted">Segera hadir pada phase berikutnya.</span>
            </div>
        @endforeach
    </div>
@endsection
