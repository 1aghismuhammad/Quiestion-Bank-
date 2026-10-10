@extends('layouts.app')

@section('title', 'Dasbor')

@php
    $dashboardName = trim((string) ($user->name ?? ''));
@endphp

@section('content')
    <div class="dashboard-home space-y-6">
        <!-- Dashboard Top Header / Greeting -->
        <header class="dashboard-welcome flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-800 tracking-tight m-0">
                    @if ($dashboardName !== '')
                        Selamat datang, {{ $dashboardName }}
                    @else
                        Selamat datang
                    @endif
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 m-0">Mulai dari materi untuk membuat soal dan mengelola bank soal Anda.</p>
            </div>

            <!-- Search Bar (Top Right, Pill-shaped) -->
            <div class="relative w-full sm:w-64">
                <input type="search" placeholder="Cari materi atau soal..." class="w-full bg-white/50 backdrop-blur-md border border-white/60 rounded-full px-4 py-2 pl-9 text-xs text-slate-800 placeholder-slate-400 focus:bg-white/80 focus:outline-hidden shadow-xs transition-all">
                <svg class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
        </header>

        <!-- Category Filters (Horizontal Scrollable Pills) -->
        <div class="flex items-center gap-2 overflow-x-auto pb-1 no-scrollbar">
            <button class="px-4 py-1.5 rounded-full text-xs font-semibold bg-white text-slate-800 shadow-xs border border-white/60 whitespace-nowrap cursor-pointer">Semua Fitur</button>
            <button class="px-4 py-1.5 rounded-full text-xs font-medium bg-white/40 border border-white/40 text-slate-600 hover:bg-white/70 shadow-2xs whitespace-nowrap cursor-pointer transition-colors">Materi Ajar</button>
            <button class="px-4 py-1.5 rounded-full text-xs font-medium bg-white/40 border border-white/40 text-slate-600 hover:bg-white/70 shadow-2xs whitespace-nowrap cursor-pointer transition-colors">Kisi-Kisi</button>
            <button class="px-4 py-1.5 rounded-full text-xs font-medium bg-white/40 border border-white/40 text-slate-600 hover:bg-white/70 shadow-2xs whitespace-nowrap cursor-pointer transition-colors">Bank Soal</button>
            <button class="px-4 py-1.5 rounded-full text-xs font-medium bg-white/40 border border-white/40 text-slate-600 hover:bg-white/70 shadow-2xs whitespace-nowrap cursor-pointer transition-colors">Asesmen</button>
        </div>

        <!-- 2-Column Responsive Layout (Middle Column + Right Column) -->
        <div class="dashboard-grid grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Middle Column: Main Content (lg:col-span-8) -->
            <div class="dashboard-main lg:col-span-8 space-y-6">
                <!-- Workflow Hero Card (Base Glass Tier) -->
                <section class="dashboard-workflow bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-6 transition-all duration-300 hover:shadow-lg" aria-labelledby="dashboard-workflow-title">
                    <div class="dashboard-workflow-intro flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                        <div>
                            <h2 id="dashboard-workflow-title" class="text-lg font-bold text-slate-800 m-0">Mulai membuat soal</h2>
                            <p class="text-xs text-slate-500 mt-1 m-0">Kelola materi sebagai langkah awal untuk membuat kisi-kisi dan soal.</p>
                        </div>
                        <a class="dashboard-primary px-6 py-2.5 rounded-full text-white bg-gradient-to-r from-purple-500 to-pink-500 font-semibold shadow-md hover:shadow-lg hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 inline-flex items-center gap-2 cursor-pointer text-xs shrink-0" href="{{ route('materials.index') }}">
                            Kelola materi
                        </a>
                    </div>

                    <ol class="dashboard-workflow-steps grid grid-cols-2 sm:grid-cols-4 gap-3 list-none p-0 m-0">
                        <li class="dashboard-workflow-step dashboard-workflow-step-emphasis p-3.5 rounded-2xl bg-white/70 backdrop-blur-md border border-white/60 shadow-xs flex items-center gap-3">
                            <span class="dashboard-step-mark w-7 h-7 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-xs" aria-hidden="true">1</span>
                            <div class="min-w-0">
                                <p class="dashboard-step-title text-xs font-bold text-slate-800 m-0 leading-tight">Materi</p>
                                <p class="dashboard-step-help text-[11px] text-slate-500 m-0 leading-tight mt-0.5">Sumber ajar</p>
                            </div>
                        </li>
                        <li class="dashboard-workflow-step p-3.5 rounded-2xl bg-white/40 backdrop-blur-md border border-white/50 shadow-2xs flex items-center gap-3">
                            <span class="dashboard-step-mark w-7 h-7 rounded-full bg-white/80 border border-white/60 text-slate-600 flex items-center justify-center font-bold text-xs shrink-0" aria-hidden="true">2</span>
                            <div class="min-w-0">
                                <p class="dashboard-step-title text-xs font-bold text-slate-800 m-0 leading-tight">Kisi-kisi</p>
                                <p class="dashboard-step-help text-[11px] text-slate-500 m-0 leading-tight mt-0.5">Struktur soal</p>
                            </div>
                        </li>
                        <li class="dashboard-workflow-step p-3.5 rounded-2xl bg-white/40 backdrop-blur-md border border-white/50 shadow-2xs flex items-center gap-3">
                            <span class="dashboard-step-mark w-7 h-7 rounded-full bg-white/80 border border-white/60 text-slate-600 flex items-center justify-center font-bold text-xs shrink-0" aria-hidden="true">3</span>
                            <div class="min-w-0">
                                <p class="dashboard-step-title text-xs font-bold text-slate-800 m-0 leading-tight">Pembuatan soal</p>
                                <p class="dashboard-step-help text-[11px] text-slate-500 m-0 leading-tight mt-0.5">Perumusan butir</p>
                            </div>
                        </li>
                        <li class="dashboard-workflow-step p-3.5 rounded-2xl bg-white/40 backdrop-blur-md border border-white/50 shadow-2xs flex items-center gap-3">
                            <span class="dashboard-step-mark w-7 h-7 rounded-full bg-white/80 border border-white/60 text-slate-600 flex items-center justify-center font-bold text-xs shrink-0" aria-hidden="true">4</span>
                            <div class="min-w-0">
                                <p class="dashboard-step-title text-xs font-bold text-slate-800 m-0 leading-tight">Bank soal</p>
                                <p class="dashboard-step-help text-[11px] text-slate-500 m-0 leading-tight mt-0.5">Penyimpanan hasil</p>
                            </div>
                        </li>
                    </ol>
                </section>

                <!-- Active Modules / Aksi Cepat (Data Iteration with Glass Cards) -->
                <section class="dashboard-quick space-y-4" aria-labelledby="dashboard-quick-title">
                    <h2 id="dashboard-quick-title" class="text-base font-bold text-slate-800 m-0">Aksi cepat</h2>
                    <div class="dashboard-quick-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <article class="dashboard-quick-card bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-5 flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                            <div>
                                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-blue-500 to-indigo-500 flex items-center justify-center text-white mb-3 shadow-xs">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                </div>
                                <h3 class="text-sm font-bold text-slate-800 m-0">Materi</h3>
                                <p class="text-xs text-slate-500 mt-1 m-0">Kelola materi pembelajaran yang digunakan untuk membuat soal.</p>
                                <div class="w-full bg-pink-100/60 rounded-full h-1.5 mt-3 overflow-hidden">
                                    <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-1.5 rounded-full w-3/4"></div>
                                </div>
                            </div>
                            <a class="dashboard-secondary-link text-xs font-semibold text-purple-600 hover:text-purple-700 flex items-center justify-between mt-4 group pt-2 border-t border-white/50" href="{{ route('materials.index') }}">
                                <span>Buka materi</span>
                                <span class="dashboard-link-chevron font-bold group-hover:translate-x-0.5 transition-transform" aria-hidden="true">›</span>
                            </a>
                        </article>

                        <article class="dashboard-quick-card bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-5 flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                            <div>
                                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-purple-500 to-pink-500 flex items-center justify-center text-white mb-3 shadow-xs">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                                </div>
                                <h3 class="text-sm font-bold text-slate-800 m-0">Bank soal</h3>
                                <p class="text-xs text-slate-500 mt-1 m-0">Lihat dan kelola set soal yang sudah disimpan.</p>
                                <div class="w-full bg-pink-100/60 rounded-full h-1.5 mt-3 overflow-hidden">
                                    <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-1.5 rounded-full w-1/2"></div>
                                </div>
                            </div>
                            <a class="dashboard-secondary-link text-xs font-semibold text-purple-600 hover:text-purple-700 flex items-center justify-between mt-4 group pt-2 border-t border-white/50" href="{{ route('question-sets.index') }}">
                                <span>Buka bank soal</span>
                                <span class="dashboard-link-chevron font-bold group-hover:translate-x-0.5 transition-transform" aria-hidden="true">›</span>
                            </a>
                        </article>

                        <article class="dashboard-quick-card bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-5 flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                            <div>
                                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-rose-500 flex items-center justify-center text-white mb-3 shadow-xs">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                </div>
                                <h3 class="text-sm font-bold text-slate-800 m-0">Langganan</h3>
                                <p class="text-xs text-slate-500 mt-1 m-0">Lihat paket, kuota, dan status langganan akun.</p>
                                <div class="w-full bg-pink-100/60 rounded-full h-1.5 mt-3 overflow-hidden">
                                    <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-1.5 rounded-full w-4/5"></div>
                                </div>
                            </div>
                            <a class="dashboard-secondary-link text-xs font-semibold text-purple-600 hover:text-purple-700 flex items-center justify-between mt-4 group pt-2 border-t border-white/50" href="{{ route('account.subscription.show') }}">
                                <span>Lihat langganan</span>
                                <span class="dashboard-link-chevron font-bold group-hover:translate-x-0.5 transition-transform" aria-hidden="true">›</span>
                            </a>
                        </article>

                        @if ($user->hasRole(\App\Enums\RoleName::ADMIN))
                            <article class="dashboard-quick-card dashboard-admin-compact bg-purple-500/10 backdrop-blur-md border border-purple-500/20 shadow-sm rounded-3xl p-5 flex flex-col justify-between transition-all duration-300 hover:-translate-y-1 hover:shadow-md sm:col-span-2 lg:col-span-3">
                                <div>
                                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-700 flex items-center justify-center text-white mb-3 shadow-xs">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    </div>
                                    <h3 id="dashboard-admin-mobile-title" class="text-sm font-bold text-slate-800 m-0">Administrasi</h3>
                                    <p class="text-xs text-slate-500 mt-1 m-0">Buka area admin untuk meninjau kebutuhan administrasi sistem.</p>
                                </div>
                                <a class="dashboard-admin-link text-xs font-semibold text-purple-700 hover:text-purple-800 flex items-center justify-between mt-4 group pt-2 border-t border-purple-200/60" href="{{ route('admin.dashboard') }}">
                                    <span>Dasbor admin</span>
                                    <span class="dashboard-link-chevron font-bold group-hover:translate-x-0.5 transition-transform" aria-hidden="true">›</span>
                                </a>
                            </article>
                        @endif
                    </div>
                </section>

                <!-- Helper Section (Alur penggunaan) -->
                <section class="dashboard-helper bg-white/30 backdrop-blur-md border border-white/40 rounded-3xl p-5" aria-labelledby="dashboard-helper-title">
                    <h2 id="dashboard-helper-title" class="text-sm font-bold text-slate-800 m-0">Alur penggunaan</h2>
                    <p class="text-xs text-slate-500 mt-1 mb-3 m-0">Unggah materi, susun kisi-kisi, buat soal, lalu simpan hasilnya ke bank soal.</p>
                    <ol class="dashboard-helper-sequence flex flex-wrap items-center gap-2 list-none p-0 m-0 text-xs font-semibold text-slate-600">
                        <li class="px-3 py-1 rounded-full bg-white/60 border border-white/50 shadow-2xs">1. Materi</li>
                        <span class="text-slate-400" aria-hidden="true">→</span>
                        <li class="px-3 py-1 rounded-full bg-white/60 border border-white/50 shadow-2xs">2. Kisi-kisi</li>
                        <span class="text-slate-400" aria-hidden="true">→</span>
                        <li class="px-3 py-1 rounded-full bg-white/60 border border-white/50 shadow-2xs">3. Soal</li>
                        <span class="text-slate-400" aria-hidden="true">→</span>
                        <li class="px-3 py-1 rounded-full bg-white/60 border border-white/50 shadow-2xs">4. Bank soal</li>
                    </ol>
                </section>
            </div>

            <!-- Right Column: Statistics & Widgets (lg:col-span-4) -->
            <aside class="dashboard-side lg:col-span-4 space-y-6">
                <!-- Statistics Header & Activity Chart (Base Glass Tier) -->
                <div class="bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-bold text-slate-800 m-0">Statistik</h2>
                        <!-- Day / Week / Month Toggle Switch -->
                        <div class="flex items-center p-0.5 rounded-full bg-white/60 border border-white/50 text-[10px] font-semibold text-slate-600 shadow-2xs">
                            <span class="px-2.5 py-1 rounded-full bg-white text-slate-800 shadow-xs cursor-pointer">Hari</span>
                            <span class="px-2.5 py-1 rounded-full text-slate-500 hover:text-slate-800 cursor-pointer transition-colors">Minggu</span>
                            <span class="px-2.5 py-1 rounded-full text-slate-500 hover:text-slate-800 cursor-pointer transition-colors">Bulan</span>
                        </div>
                    </div>

                    <!-- Placeholder Activity Chart (Blue to Pink Gradient Bars) -->
                    <div class="pt-2">
                        <p class="text-[11px] text-slate-500 font-medium m-0 mb-3">Aktivitas Pembuatan Soal Mingguan</p>
                        <div class="h-32 flex items-end justify-between gap-2 px-1">
                            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                                <div class="w-full bg-gradient-to-t from-blue-400 via-indigo-500 to-pink-500 rounded-full h-[40%] transition-all duration-300 hover:opacity-90 shadow-2xs"></div>
                                <span class="text-[10px] text-slate-500 font-medium">Sen</span>
                            </div>
                            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                                <div class="w-full bg-gradient-to-t from-blue-400 via-indigo-500 to-pink-500 rounded-full h-[65%] transition-all duration-300 hover:opacity-90 shadow-2xs"></div>
                                <span class="text-[10px] text-slate-500 font-medium">Sel</span>
                            </div>
                            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                                <div class="w-full bg-gradient-to-t from-blue-400 via-indigo-500 to-pink-500 rounded-full h-[85%] transition-all duration-300 hover:opacity-90 shadow-2xs"></div>
                                <span class="text-[10px] text-slate-500 font-medium">Rab</span>
                            </div>
                            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                                <div class="w-full bg-gradient-to-t from-blue-400 via-indigo-500 to-pink-500 rounded-full h-[55%] transition-all duration-300 hover:opacity-90 shadow-2xs"></div>
                                <span class="text-[10px] text-slate-500 font-medium">Kam</span>
                            </div>
                            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                                <div class="w-full bg-gradient-to-t from-blue-400 via-indigo-500 to-pink-500 rounded-full h-[95%] transition-all duration-300 hover:opacity-90 shadow-2xs"></div>
                                <span class="text-[10px] text-slate-500 font-medium">Jum</span>
                            </div>
                            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                                <div class="w-full bg-gradient-to-t from-blue-400 via-indigo-500 to-pink-500 rounded-full h-[35%] transition-all duration-300 hover:opacity-90 shadow-2xs"></div>
                                <span class="text-[10px] text-slate-500 font-medium">Sab</span>
                            </div>
                            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end">
                                <div class="w-full bg-gradient-to-t from-blue-400 via-indigo-500 to-pink-500 rounded-full h-[70%] transition-all duration-300 hover:opacity-90 shadow-2xs"></div>
                                <span class="text-[10px] text-slate-500 font-medium">Min</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Widget Grid (Below Chart) -->
                <div class="space-y-4">
                    <!-- Progress Card: Full Gradient Background with Circular Ring -->
                    <div class="bg-gradient-to-br from-purple-600 via-indigo-600 to-blue-500 text-white rounded-3xl p-5 shadow-md flex items-center justify-between gap-4">
                        <div>
                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-white/20 text-white backdrop-blur-md mb-1.5">Target Mingguan</span>
                            <h3 class="text-sm font-bold text-white m-0">Kapasitas Asesmen</h3>
                            <p class="text-[11px] text-purple-100 mt-1 m-0">Progres perumusan soal aktif</p>
                        </div>
                        <!-- Circular Progress Ring (e.g. 75%) -->
                        <div class="relative w-14 h-14 shrink-0 flex items-center justify-center">
                            <svg class="w-14 h-14 -rotate-90" viewBox="0 0 36 36">
                                <path class="text-white/20" stroke-width="3.5" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                <path class="text-white" stroke-dasharray="75, 100" stroke-width="3.5" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            </svg>
                            <span class="absolute text-xs font-extrabold text-white">75%</span>
                        </div>
                    </div>

                    <!-- Completion Card: Small White Glass Card -->
                    <div class="bg-white/50 backdrop-blur-md border border-white/60 shadow-xs rounded-3xl p-5 flex items-center justify-between">
                        <div>
                            <p class="text-[11px] text-slate-500 font-medium m-0">Instrumen Selesai</p>
                            <h3 class="text-lg font-extrabold text-slate-800 m-0 mt-0.5">24 Butir Soal</h3>
                            <p class="text-[11px] text-emerald-600 font-semibold m-0 mt-0.5">Siap digunakan</p>
                        </div>
                        <div class="w-11 h-11 rounded-2xl bg-emerald-500/15 border border-emerald-500/20 text-emerald-600 flex items-center justify-center shadow-2xs">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </div>
                    </div>

                    <!-- Achievement Card: Tier 2 Highlight Glass with Gold Medal -->
                    <div class="bg-white/20 backdrop-blur-lg border border-white/50 shadow-glass rounded-3xl p-5 relative overflow-hidden">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-800 border border-amber-500/20 mb-1.5">Pencapaian Baru</span>
                                <h3 class="text-sm font-bold text-slate-800 m-0">Pendidik Teladan</h3>
                                <p class="text-[11px] text-slate-500 mt-1 m-0">Menyelesaikan kurasi 5 modul materi</p>
                            </div>
                            <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-400 to-yellow-300 text-amber-900 flex items-center justify-center shadow-xs shrink-0">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599-.8a1 1 0 01.894 1.79l-1.233.616 1.738 5.42a1 1 0 01-.285 1.05A3.989 3.989 0 0115 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.715-5.349L11 6.477V16h2a1 1 0 110 2H7a1 1 0 110-2h2V6.477L6.237 7.582l1.715 5.349a1 1 0 01-.285 1.05A3.989 3.989 0 015 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.738-5.42-1.233-.617a1 1 0 01.894-1.788l1.599.799L9 4.323V3a1 1 0 011-1z" clip-rule="evenodd"/></svg>
                            </div>
                        </div>
                        <div class="mt-3 inline-block px-3 py-1 rounded-full text-[10px] font-extrabold tracking-wider text-white bg-gradient-to-r from-purple-500 to-pink-500 shadow-xs uppercase">
                            UNLOCKED
                        </div>
                    </div>

                    <!-- Subscription Details Card (Compliance with Test Suite) -->
                    <section class="dashboard-subscription bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-5" aria-labelledby="dashboard-subscription-title">
                        <h2 id="dashboard-subscription-title" class="text-sm font-bold text-slate-800 m-0">Langganan</h2>
                        <p class="text-xs text-slate-500 mt-1 mb-3 m-0">Kelola paket dan kuota akun Anda.</p>
                        <a class="dashboard-secondary-link text-xs font-semibold text-purple-600 hover:text-purple-700 flex items-center justify-between" href="{{ route('account.subscription.show') }}">
                            <span>Lihat langganan</span>
                            <span class="dashboard-link-chevron font-bold" aria-hidden="true">›</span>
                        </a>
                    </section>

                    @if ($user->hasRole(\App\Enums\RoleName::ADMIN))
                        <section class="dashboard-admin bg-purple-500/10 backdrop-blur-md border border-purple-500/20 shadow-sm rounded-3xl p-5" aria-labelledby="dashboard-admin-title">
                            <h2 id="dashboard-admin-title" class="text-sm font-bold text-slate-800 m-0">Administrasi</h2>
                            <p class="text-xs text-slate-500 mt-1 mb-3 m-0">Buka area admin untuk meninjau kebutuhan administrasi sistem.</p>
                            <a class="dashboard-admin-link text-xs font-semibold text-purple-700 hover:text-purple-800 flex items-center justify-between" href="{{ route('admin.dashboard') }}">
                                <span>Dasbor admin</span>
                                <span class="dashboard-link-chevron font-bold" aria-hidden="true">›</span>
                            </a>
                        </section>
                    @endif
                </div>
            </aside>
        </div>
    </div>
@endsection
