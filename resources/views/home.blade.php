@extends('layouts.app')

@section('title', 'AI Question Bank')

@section('content')
    <div class="card" style="max-width: 720px; margin: 48px auto; text-align: center; padding: 48px;">
        <p class="muted">AI QUESTION BANK SAAS</p>
        <h1>Buat bank soal dari materi pembelajaran</h1>
        <p class="muted">
            Masuk menggunakan akun Google untuk mengakses dashboard dan melengkapi profil.
        </p>

        @auth
            <a class="button" href="{{ route('dashboard') }}">Buka Dashboard</a>
        @else
            <a class="button" href="{{ route('auth.google.redirect') }}">Login dengan Google</a>
        @endauth
    </div>
@endsection
