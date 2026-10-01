@extends('layouts.app')

@section('title', 'Dasbor')

@php
    $dashboardName = trim((string) ($user->name ?? ''));
@endphp

@section('content')
    <div class="dashboard-home">
        <header class="dashboard-welcome">
            <h1>
                @if ($dashboardName !== '')
                    Selamat datang, {{ $dashboardName }}
                @else
                    Selamat datang
                @endif
            </h1>
            <p>Mulai dari materi untuk membuat soal dan mengelola bank soal Anda.</p>
        </header>

        <div class="dashboard-grid">
            <div class="dashboard-main">
                <section class="dashboard-workflow" aria-labelledby="dashboard-workflow-title">
                    <div class="dashboard-workflow-intro">
                        <h2 id="dashboard-workflow-title">Mulai membuat soal</h2>
                        <p>Kelola materi sebagai langkah awal untuk membuat kisi-kisi dan soal.</p>
                        <a class="dashboard-primary" href="{{ route('materials.index') }}">Kelola materi</a>
                    </div>

                    <ol class="dashboard-workflow-steps">
                        <li class="dashboard-workflow-step dashboard-workflow-step-emphasis">
                            <span class="dashboard-step-mark" aria-hidden="true">1</span>
                            <div>
                                <p class="dashboard-step-title">Materi</p>
                                <p class="dashboard-step-help">Sumber ajar</p>
                            </div>
                        </li>
                        <li class="dashboard-workflow-step">
                            <span class="dashboard-step-mark" aria-hidden="true">2</span>
                            <div>
                                <p class="dashboard-step-title">Kisi-kisi</p>
                                <p class="dashboard-step-help">Struktur soal</p>
                            </div>
                        </li>
                        <li class="dashboard-workflow-step">
                            <span class="dashboard-step-mark" aria-hidden="true">3</span>
                            <div>
                                <p class="dashboard-step-title">Pembuatan soal</p>
                                <p class="dashboard-step-help">Perumusan butir</p>
                            </div>
                        </li>
                        <li class="dashboard-workflow-step">
                            <span class="dashboard-step-mark" aria-hidden="true">4</span>
                            <div>
                                <p class="dashboard-step-title">Bank soal</p>
                                <p class="dashboard-step-help">Penyimpanan hasil</p>
                            </div>
                        </li>
                    </ol>
                </section>

                <section class="dashboard-quick" aria-labelledby="dashboard-quick-title">
                    <h2 id="dashboard-quick-title">Aksi cepat</h2>
                    <div class="dashboard-quick-grid">
                        <article class="dashboard-quick-card">
                            <h3>Materi</h3>
                            <p>Kelola materi pembelajaran yang digunakan untuk membuat soal.</p>
                            <a class="dashboard-secondary-link" href="{{ route('materials.index') }}"><span>Buka materi</span><span class="dashboard-link-chevron" aria-hidden="true">›</span></a>
                        </article>
                        <article class="dashboard-quick-card">
                            <h3>Bank soal</h3>
                            <p>Lihat dan kelola set soal yang sudah disimpan.</p>
                            <a class="dashboard-secondary-link" href="{{ route('question-sets.index') }}"><span>Buka bank soal</span><span class="dashboard-link-chevron" aria-hidden="true">›</span></a>
                        </article>
                        <article class="dashboard-quick-card">
                            <h3>Langganan</h3>
                            <p>Lihat paket, kuota, dan status langganan akun.</p>
                            <a class="dashboard-secondary-link" href="{{ route('account.subscription.show') }}"><span>Lihat langganan</span><span class="dashboard-link-chevron" aria-hidden="true">›</span></a>
                        </article>
                        @if ($user->hasRole(\App\Enums\RoleName::ADMIN))
                            <article class="dashboard-quick-card dashboard-admin-compact">
                                <h3 id="dashboard-admin-mobile-title">Administrasi</h3>
                                <p>Buka area admin untuk meninjau kebutuhan administrasi sistem.</p>
                                <a class="dashboard-admin-link" href="{{ route('admin.dashboard') }}"><span>Dasbor admin</span><span class="dashboard-link-chevron" aria-hidden="true">›</span></a>
                            </article>
                        @endif
                    </div>
                </section>

                <section class="dashboard-helper" aria-labelledby="dashboard-helper-title">
                    <h2 id="dashboard-helper-title">Alur penggunaan</h2>
                    <p>Unggah materi, susun kisi-kisi, buat soal, lalu simpan hasilnya ke bank soal.</p>
                    <ol class="dashboard-helper-sequence">
                        <li>Materi</li>
                        <li>Kisi-kisi</li>
                        <li>Soal</li>
                        <li>Bank soal</li>
                    </ol>
                </section>
            </div>

            <aside class="dashboard-side">
                <section class="dashboard-subscription" aria-labelledby="dashboard-subscription-title">
                    <h2 id="dashboard-subscription-title">Langganan</h2>
                    <p>Kelola paket dan kuota akun Anda.</p>
                    <a class="dashboard-secondary-link" href="{{ route('account.subscription.show') }}"><span>Lihat langganan</span><span class="dashboard-link-chevron" aria-hidden="true">›</span></a>
                </section>

                @if ($user->hasRole(\App\Enums\RoleName::ADMIN))
                    <section class="dashboard-admin" aria-labelledby="dashboard-admin-title">
                        <h2 id="dashboard-admin-title">Administrasi</h2>
                        <p>Buka area admin untuk meninjau kebutuhan administrasi sistem.</p>
                        <a class="dashboard-admin-link" href="{{ route('admin.dashboard') }}"><span>Dasbor admin</span><span class="dashboard-link-chevron" aria-hidden="true">›</span></a>
                    </section>
                @endif
            </aside>
        </div>
    </div>
@endsection
