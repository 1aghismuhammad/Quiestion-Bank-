@extends('layouts.app')

@section('title', 'AI Question Bank')

@section('content')
    <div class="public-home-page">
        <section class="public-home-hero">
            <x-ui.page-header class="public-home-header">
                Masuk ke AI Question Bank
                <x-slot:supporting>
                    Buat bank soal dari materi pembelajaran.
                </x-slot:supporting>
            </x-ui.page-header>

            @auth
                <x-ui.button href="{{ route('dashboard') }}">Buka dasbor</x-ui.button>
            @else
                <x-ui.button class="public-home-cta" href="{{ route('login') }}">
                    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                    Login dengan Google
                </x-ui.button>
                <p class="public-home-helper muted">Masuk memakai akun Google.</p>
            @endauth
        </section>

        <ul class="public-home-capabilities" aria-label="Fitur utama">
            <li class="public-home-capability">
                <span class="public-home-capability-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </span>
                <div>
                    <h2>Kelola Materi Pembelajaran</h2>
                    <p class="muted">Gunakan materi sebagai sumber kerja pembuatan soal.</p>
                </div>
            </li>
            <li class="public-home-capability">
                <span class="public-home-capability-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                </span>
                <div>
                    <h2>Rancang Kisi-Kisi &amp; Buat Soal</h2>
                    <p class="muted">Susun blueprint/kisi-kisi lalu lanjutkan ke pembuatan soal.</p>
                </div>
            </li>
            <li class="public-home-capability">
                <span class="public-home-capability-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                </span>
                <div>
                    <h2>Simpan ke Bank Soal</h2>
                    <p class="muted">Simpan Question Set dan gunakan hasil akhirnya sesuai workflow aplikasi.</p>
                </div>
            </li>
        </ul>
    </div>
@endsection
