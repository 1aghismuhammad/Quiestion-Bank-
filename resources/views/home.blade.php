@extends('layouts.app')

@section('title', 'AI Question Bank')

@section('content')
    <div class="public-home-page max-w-5xl mx-auto space-y-12 sm:space-y-16 py-4">
        <!-- 1. HERO SECTION -->
        <section class="public-home-hero text-center pt-4 sm:pt-8">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-semibold bg-white/60 backdrop-blur-md text-purple-700 border border-white/60 shadow-xs mb-6">
                <span class="flex h-2 w-2 rounded-full bg-gradient-to-r from-purple-500 to-pink-500" aria-hidden="true"></span>
                <span>Platform Cerdas Asesmen Pembelajaran</span>
            </div>

            <h1 class="text-4xl sm:text-6xl lg:text-7xl font-extrabold text-slate-800 tracking-tight leading-tight mb-4 max-w-4xl mx-auto">
                Masuk ke AI Question Bank
            </h1>

            <p class="text-base sm:text-lg lg:text-xl text-slate-600 max-w-2xl mx-auto mb-8 font-normal leading-relaxed">
                Buat bank soal dari materi pembelajaran.
            </p>

            <div class="flex flex-col items-center justify-center">
                @auth
                    <x-ui.button href="{{ route('dashboard') }}" variant="primary">Buka dasbor</x-ui.button>
                @else
                    <a class="public-home-cta px-8 py-3.5 rounded-full text-white bg-gradient-to-r from-purple-500 to-pink-500 font-semibold shadow-md hover:shadow-lg hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 inline-flex items-center gap-3 cursor-pointer" href="{{ route('login') }}">
                        <svg class="w-5 h-5 shrink-0" aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z" fill="#FBBC05"/>
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z" fill="#EA4335"/>
                        </svg>
                        <span>Login dengan Google</span>
                    </a>
                    <p class="public-home-helper muted text-xs text-slate-500 mt-3 font-medium">Masuk memakai akun Google.</p>
                @endauth
            </div>

            <!-- Floating 3D Mockup Card (Highlight Glass Tier) -->
            <div class="mt-10 sm:mt-12 bg-white/20 backdrop-blur-lg border border-white/50 shadow-glass rounded-3xl p-6 sm:p-8 max-w-3xl mx-auto text-left relative overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                <div class="flex items-center justify-between pb-4 border-b border-white/40 mb-5">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full bg-rose-400"></span>
                        <span class="w-3 h-3 rounded-full bg-amber-400"></span>
                        <span class="w-3 h-3 rounded-full bg-emerald-400"></span>
                        <span class="text-xs font-semibold text-slate-600 ml-2">Asesmen Cerdas AI</span>
                    </div>
                    <span class="px-3 py-1 rounded-full text-[11px] font-semibold bg-white/60 text-purple-700 border border-white/60 shadow-xs">Instrumen Otomatis</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="bg-white/50 backdrop-blur-md rounded-2xl p-4 border border-white/60 shadow-xs">
                        <p class="text-xs text-slate-500 font-medium m-0">Materi Masuk</p>
                        <p class="text-base font-bold text-slate-800 mt-1 m-0">Dokumen PDF/Word</p>
                        <div class="w-full bg-purple-100 rounded-full h-1.5 mt-3 overflow-hidden">
                            <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-1.5 rounded-full w-full"></div>
                        </div>
                    </div>
                    <div class="bg-white/50 backdrop-blur-md rounded-2xl p-4 border border-white/60 shadow-xs">
                        <p class="text-xs text-slate-500 font-medium m-0">Kisi-Kisi</p>
                        <p class="text-base font-bold text-slate-800 mt-1 m-0">Struktur Terarah</p>
                        <div class="w-full bg-purple-100 rounded-full h-1.5 mt-3 overflow-hidden">
                            <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-1.5 rounded-full w-4/5"></div>
                        </div>
                    </div>
                    <div class="bg-white/50 backdrop-blur-md rounded-2xl p-4 border border-white/60 shadow-xs">
                        <p class="text-xs text-slate-500 font-medium m-0">Bank Soal</p>
                        <p class="text-base font-bold text-slate-800 mt-1 m-0">Format Lengkap</p>
                        <div class="w-full bg-purple-100 rounded-full h-1.5 mt-3 overflow-hidden">
                            <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-1.5 rounded-full w-3/4"></div>
                        </div>
                    </div>
                </div>

                <div class="pt-5 mt-5 border-t border-white/40 flex flex-wrap items-center justify-center gap-y-2 gap-x-6 text-xs text-slate-600 font-medium">
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-600" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Akses Instan Akun Google
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-600" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Dukungan PDF, Word &amp; Teks
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-600" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Unduh Dokumen Siswa &amp; Guru
                    </span>
                </div>
            </div>
        </section>

        <!-- 2. PEDAGOGICAL WORKFLOW SECTION (Base Glass Tier) -->
        <section class="bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-6 sm:p-8">
            <div class="mb-6">
                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-purple-500/10 text-purple-700 border border-purple-500/20 mb-2">Alur Kerja Sistematis</span>
                <p class="text-sm text-slate-600 m-0">Tiga tahapan terarah dari bahan ajar menjadi dokumen instrumen siap pakai.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-5 rounded-2xl bg-white/50 backdrop-blur-md border border-white/60 shadow-xs flex flex-col gap-2 transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white text-xs font-extrabold shadow-sm">1</span>
                    <h3 class="text-sm font-bold text-slate-800 m-0">Unggah Bahan Ajar</h3>
                    <p class="text-xs text-slate-500 m-0 leading-relaxed">Ekstraksi topik, indikator, dan konsep dari modul pembelajaran.</p>
                </div>
                <div class="p-5 rounded-2xl bg-white/50 backdrop-blur-md border border-white/60 shadow-xs flex flex-col gap-2 transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white text-xs font-extrabold shadow-sm">2</span>
                    <h3 class="text-sm font-bold text-slate-800 m-0">Susun Kisi-Kisi</h3>
                    <p class="text-xs text-slate-500 m-0 leading-relaxed">Petakan indikator soal, jumlah butir, dan variasi tingkat kesukaran.</p>
                </div>
                <div class="p-5 rounded-2xl bg-white/50 backdrop-blur-md border border-white/60 shadow-xs flex flex-col gap-2 transition-all duration-300 hover:-translate-y-1 hover:shadow-md">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white text-xs font-extrabold shadow-sm">3</span>
                    <h3 class="text-sm font-bold text-slate-800 m-0">Hasilkan &amp; Unduh</h3>
                    <p class="text-xs text-slate-500 m-0 leading-relaxed">Telaah butir soal terpadu, lalu unduh format Word versi Siswa atau Guru.</p>
                </div>
            </div>
        </section>

        <!-- 3. CORE CAPABILITIES (Test-Grounded, Base Glass Tier) -->
        <ul class="public-home-capabilities grid grid-cols-1 sm:grid-cols-3 gap-4 list-none p-0 m-0" aria-label="Fitur utama">
            <li class="public-home-capability bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg flex flex-col gap-4">
                <span class="public-home-capability-icon inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-white/80 border border-white/60 text-purple-600 shadow-xs" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </span>
                <div>
                    <h2 class="text-base font-bold text-slate-800 m-0 mb-1.5">Kelola Materi Pembelajaran</h2>
                    <p class="muted text-xs text-slate-500 m-0 leading-relaxed">Gunakan materi sebagai sumber kerja pembuatan soal.</p>
                </div>
            </li>
            <li class="public-home-capability bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg flex flex-col gap-4">
                <span class="public-home-capability-icon inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-white/80 border border-white/60 text-purple-600 shadow-xs" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                </span>
                <div>
                    <h2 class="text-base font-bold text-slate-800 m-0 mb-1.5">Rancang Kisi-Kisi &amp; Buat Soal</h2>
                    <p class="muted text-xs text-slate-500 m-0 leading-relaxed">Susun blueprint/kisi-kisi lalu lanjutkan ke pembuatan soal.</p>
                </div>
            </li>
            <li class="public-home-capability bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg flex flex-col gap-4">
                <span class="public-home-capability-icon inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-white/80 border border-white/60 text-purple-600 shadow-xs" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                </span>
                <div>
                    <h2 class="text-base font-bold text-slate-800 m-0 mb-1.5">Simpan ke Bank Soal</h2>
                    <p class="muted text-xs text-slate-500 m-0 leading-relaxed">Simpan Question Set dan gunakan hasil akhirnya sesuai workflow aplikasi.</p>
                </div>
            </li>
        </ul>

        <!-- 4. PLAN HIGHLIGHTS (Base Glass Tier) -->
        <section class="bg-white/40 backdrop-blur-md border border-white/40 shadow-sm rounded-3xl p-6 sm:p-8">
            <div class="mb-6">
                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-purple-500/10 text-purple-700 border border-purple-500/20 mb-2">Pilihan Akses &amp; Kapasitas</span>
                <p class="text-sm text-slate-600 m-0">Disesuaikan untuk mendukung kebutuhan penyusunan asesmen berkala Anda.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-5 rounded-2xl border border-white/60 bg-white/50 backdrop-blur-md flex flex-col justify-between shadow-xs">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-bold text-slate-800 m-0">Akses Dasar</h3>
                            <span class="px-2.5 py-0.5 text-[10px] font-semibold rounded-full bg-slate-200/70 text-slate-700">Akun Awal</span>
                        </div>
                        <p class="text-xs text-slate-500 m-0 mb-4">Tersedia langsung saat pertama kali masuk akun.</p>
                        <ul class="text-xs text-slate-600 flex flex-col gap-2 list-none p-0 m-0">
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-600 shrink-0" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>Kapasitas pembuatan instrumen soal</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-600 shrink-0" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>Unggah bahan ajar digital</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-600 shrink-0" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>Unduh dokumen Word versi lengkap</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="p-5 rounded-2xl border border-purple-200/80 bg-gradient-to-b from-purple-50/60 to-white/60 backdrop-blur-md flex flex-col justify-between shadow-xs">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-sm font-bold text-purple-950 m-0">Akses Lanjutan</h3>
                            <span class="px-2.5 py-0.5 text-[10px] font-semibold rounded-full bg-purple-100 text-purple-700">Kapasitas Luas</span>
                        </div>
                        <p class="text-xs text-slate-500 m-0 mb-4">Untuk persiapan ujian semester dan paket soal gabungan.</p>
                        <ul class="text-xs text-slate-600 flex flex-col gap-2 list-none p-0 m-0">
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-600 shrink-0" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>Kuota pembuatan butir soal bulanan</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-600 shrink-0" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>Kombinasi Pilihan Ganda, Benar/Salah &amp; Esai</span>
                            </li>
                            <li class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-600 shrink-0" aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span>Kapasitas penyimpanan bahan diperbesar</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- 5. FOOTER & TRUST -->
        <footer class="pt-6 border-t border-white/50 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500">
            <p class="m-0 font-medium">© {{ date('Y') }} AI Question Bank. Hak cipta dilindungi.</p>
            <div class="inline-flex items-center gap-1.5 font-medium">
                <svg class="w-4 h-4 text-emerald-600" aria-hidden="true" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1.944A11.954 11.954 0 012.166 5C2.056 5.649 2 6.319 2 7c0 5.225 3.34 9.67 8 11.317C14.66 16.67 18 12.225 18 7c0-.682-.057-1.35-.166-2.001A11.954 11.954 0 0110 1.944zM11 14a1 1 0 11-2 0 1 1 0 012 0zm0-7a1 1 0 10-2 0v3a1 1 0 102 0V7z" clip-rule="evenodd"/></svg>
                <span>Privasi Akun &amp; Dokumen Terlindungi</span>
            </div>
        </footer>
    </div>
@endsection
