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
        <a class="card placeholder" href="{{ route('materials.index') }}" style="text-decoration: none;">
            <strong>Material Management</strong>
            <span class="muted">Kelola materi, topik, dan arsip.</span>
        </a>
        <a class="card placeholder" href="{{ route('materials.index') }}" style="text-decoration: none;">
            <strong>Generate Question</strong>
            <span class="muted">Pilih materi siap, lalu atur dan mulai generasi soal.</span>
        </a>
        <a class="card placeholder" href="{{ route('generations.index') }}" style="text-decoration: none;">
            <strong>History</strong>
            <span class="muted">Lihat riwayat generasi soal Anda.</span>
        </a>
        <a class="card placeholder" href="{{ route('account.subscription.show') }}" style="text-decoration: none;">
            <strong>Subscription</strong>
            <span class="muted">Paket, kuota penyimpanan, dan upgrade.</span>
        </a>
        <a class="card placeholder" href="{{ route('question-sets.index') }}" style="text-decoration: none;">
            <strong>Question Bank</strong>
            <span class="muted">Simpan dan lihat soal dari generasi yang selesai.</span>
        </a>
    </div>
@endsection
