<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'AI Question Bank')</title>
    <style>[hidden] { display: none !important; }</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen text-slate-800 antialiased relative">
    <div class="fixed inset-0 -z-10 bg-gradient-to-br from-[#E3F2FD] via-[#F3E5F5] to-[#FFEBEE]" aria-hidden="true"></div>

    <a class="skip-link" href="#main">Langsung ke isi</a>

    @guest
        <header class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 flex items-center justify-between">
            <a class="flex items-center gap-3 font-bold text-slate-800 text-lg hover:opacity-90 transition-opacity" href="{{ route('home') }}">
                <div class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-purple-500 to-pink-500 flex items-center justify-center text-white text-sm font-extrabold shadow-md">AI</div>
                <span class="tracking-tight">AI Question Bank</span>
            </a>
        </header>

        <main id="main" class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6" tabindex="-1">
            @if (session('success'))
                <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
            @endif

            @if (session('error'))
                <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
            @endif

            @yield('content')
        </main>
    @endguest

    @auth
        <!-- Mobile/Tablet Topbar -->
        <header class="md:hidden sticky top-0 z-30 bg-white/70 backdrop-blur-lg border-b border-white/50 px-4 py-3 flex items-center justify-between shadow-xs">
            <a class="shell-brand flex items-center gap-2.5 font-bold text-slate-800 text-base" href="{{ route('home') }}">
                <div class="w-7 h-7 rounded-xl bg-gradient-to-tr from-purple-500 to-pink-500 flex items-center justify-center text-white text-xs font-bold shadow-xs">AI</div>
                <span>AI Question Bank</span>
            </a>

            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 p-1 pl-2 pr-3 rounded-full bg-white/60 border border-white/50 text-xs font-semibold text-slate-700">
                    <span class="w-5 h-5 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white flex items-center justify-center text-[10px]">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="max-w-[100px] truncate">{{ auth()->user()->name }}</span>
                </div>

                <details class="shell-menu">
                    <summary class="shell-menu-toggle flex items-center justify-center w-9 h-9 rounded-xl bg-white/60 border border-white/50 text-slate-700 list-none cursor-pointer hover:bg-white/90 transition-colors shadow-xs" aria-label="Menu">
                        <span class="shell-menu-icon" aria-hidden="true"></span>
                    </summary>
                    <div class="shell-menu-panel absolute right-0 top-full mt-2 w-56 p-2 rounded-2xl bg-white/90 backdrop-blur-xl border border-white/60 shadow-xl z-50">
                        <nav class="shell-menu-nav flex flex-col gap-1" aria-label="Utama">
                            <a class="px-3 py-2 rounded-xl text-xs font-semibold {{ request()->routeIs('dashboard') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white' : 'text-slate-700 hover:bg-white/80' }}" href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dasbor</a>
                            <a class="px-3 py-2 rounded-xl text-xs font-semibold {{ request()->routeIs('materials.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white' : 'text-slate-700 hover:bg-white/80' }}" href="{{ route('materials.index') }}" @if (request()->routeIs('materials.index')) aria-current="page" @endif>Materi</a>
                            <a class="px-3 py-2 rounded-xl text-xs font-semibold {{ request()->routeIs('generations.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white' : 'text-slate-700 hover:bg-white/80' }}" href="{{ route('generations.index') }}" @if (request()->routeIs('generations.index')) aria-current="page" @endif>Pembuatan soal</a>
                            <a class="px-3 py-2 rounded-xl text-xs font-semibold {{ request()->routeIs('question-sets.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white' : 'text-slate-700 hover:bg-white/80' }}" href="{{ route('question-sets.index') }}" @if (request()->routeIs('question-sets.index')) aria-current="page" @endif>Bank soal</a>
                            <a class="px-3 py-2 rounded-xl text-xs font-semibold {{ request()->routeIs('account.subscription.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white' : 'text-slate-700 hover:bg-white/80' }}" href="{{ route('account.subscription.show') }}" @if (request()->routeIs('account.subscription.show')) aria-current="page" @endif>Langganan</a>
                            @if (auth()->user()->hasRole(\App\Enums\RoleName::ADMIN))
                                <a class="px-3 py-2 rounded-xl text-xs font-semibold text-purple-700 hover:bg-purple-50" href="{{ route('admin.dashboard') }}">Dasbor admin</a>
                            @endif
                        </nav>
                        <div class="pt-2 mt-2 border-t border-slate-100">
                            <p class="shell-menu-user text-[11px] text-slate-500 px-3 py-1 truncate">{{ auth()->user()->name }}</p>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="shell-logout w-full text-left px-3 py-2 rounded-xl text-xs font-semibold text-rose-600 hover:bg-rose-50 transition-colors cursor-pointer" type="submit">Keluar</button>
                            </form>
                        </div>
                    </div>
                </details>
            </div>
        </header>

        <!-- Responsive Dashboard Grid -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col md:flex-row gap-6">
            <!-- Left Column: Sidebar (Desktop / Tablet) -->
            <aside class="hidden md:flex flex-col w-[240px] lg:w-[280px] shrink-0 sticky top-6 self-start bg-white/40 backdrop-blur-md border border-white/50 shadow-sm rounded-3xl p-5 gap-6 max-h-[calc(100vh-3rem)] overflow-y-auto">
                <a class="shell-brand flex items-center gap-3 font-bold text-slate-800 text-lg px-1 hover:opacity-90 transition-opacity" href="{{ route('home') }}">
                    <div class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-purple-500 to-pink-500 flex items-center justify-center text-white text-sm font-extrabold shadow-sm">AI</div>
                    <span class="tracking-tight">AI Question Bank</span>
                </a>

                <!-- Profile Header -->
                <div class="flex items-center gap-3 p-2.5 rounded-full bg-white/60 border border-white/50 shadow-xs">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-tr from-purple-500 to-pink-500 text-white flex items-center justify-center font-bold text-sm shadow-xs shrink-0">
                        {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-slate-800 truncate m-0 leading-tight">{{ auth()->user()->name }}</p>
                        <p class="text-[11px] text-slate-500 truncate m-0 leading-tight mt-0.5">
                            {{ auth()->user()->hasRole(\App\Enums\RoleName::ADMIN) ? 'Administrator' : 'Pendidik' }}
                        </p>
                    </div>
                </div>

                <!-- Navigation Links -->
                <nav class="flex flex-col gap-1.5" aria-label="Utama">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-full text-sm font-semibold transition-all duration-200 {{ request()->routeIs('dashboard') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-md' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900' }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        <span>Dasbor</span>
                    </a>
                    <a href="{{ route('materials.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-full text-sm font-semibold transition-all duration-200 {{ request()->routeIs('materials.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-md' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900' }}" @if (request()->routeIs('materials.index')) aria-current="page" @endif>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <span>Materi</span>
                    </a>
                    <a href="{{ route('generations.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-full text-sm font-semibold transition-all duration-200 {{ request()->routeIs('generations.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-md' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900' }}" @if (request()->routeIs('generations.index')) aria-current="page" @endif>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span>Pembuatan soal</span>
                    </a>
                    <a href="{{ route('question-sets.index') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-full text-sm font-semibold transition-all duration-200 {{ request()->routeIs('question-sets.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-md' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900' }}" @if (request()->routeIs('question-sets.index')) aria-current="page" @endif>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                        <span>Bank soal</span>
                    </a>
                    <a href="{{ route('account.subscription.show') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-full text-sm font-semibold transition-all duration-200 {{ request()->routeIs('account.subscription.*') ? 'bg-gradient-to-r from-purple-500 to-pink-500 text-white shadow-md' : 'text-slate-600 hover:bg-white/60 hover:text-slate-900' }}" @if (request()->routeIs('account.subscription.show')) aria-current="page" @endif>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        <span>Langganan</span>
                    </a>
                    @if (auth()->user()->hasRole(\App\Enums\RoleName::ADMIN))
                        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 px-4 py-2.5 rounded-full text-sm font-semibold transition-all duration-200 text-purple-700 hover:bg-purple-100/60 mt-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span>Dasbor admin</span>
                        </a>
                    @endif
                </nav>

                @if (request()->routeIs('dashboard'))
                    <!-- Promo Card (Tier 2 Glass) -->
                    <div class="bg-white/20 backdrop-blur-lg border border-white/50 shadow-glass rounded-3xl p-4 mt-auto relative overflow-hidden">
                        <div class="relative z-10">
                            <div class="w-7 h-7 rounded-lg bg-gradient-to-tr from-purple-500 to-pink-500 flex items-center justify-center text-white mb-2 shadow-xs">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                            </div>
                            <h4 class="text-xs font-bold text-slate-800 m-0">Upgrade Account</h4>
                            <p class="text-[11px] text-slate-600 m-0 mt-1 leading-relaxed">Unlock premium features.</p>
                            <a href="{{ route('account.subscription.show') }}" class="block text-center mt-3 py-1.5 px-3 rounded-full text-xs font-semibold bg-[#2a1758] hover:bg-[#1e1040] text-white transition-all shadow-xs">
                                Upgrade
                            </a>
                        </div>
                    </div>
                @endif

                <!-- Logout -->
                <div class="pt-2 border-t border-white/40">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="shell-logout w-full py-2 px-4 rounded-full bg-white/50 hover:bg-white/80 text-slate-700 text-xs font-semibold border border-white/50 transition-all cursor-pointer flex items-center justify-center gap-2" type="submit">
                            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            <span>Keluar</span>
                        </button>
                    </form>
                </div>
            </aside>

            <!-- Middle Column: Main Content -->
            <main id="main" class="flex-1 min-w-0 pb-20 md:pb-6" tabindex="-1">
                @if (session('success'))
                    <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
                @endif

                @if (session('error'))
                    <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
                @endif

                @yield('content')
            </main>
        </div>

        <!-- Mobile Bottom Navigation Bar -->
        <nav class="fixed bottom-0 inset-x-0 z-40 md:hidden bg-white/70 backdrop-blur-lg border-t border-white/50 py-2 px-2 flex items-center justify-around shadow-glass" aria-label="Navigasi Bawah">
            <a href="{{ route('dashboard') }}" class="flex flex-col items-center gap-1 text-[10px] font-semibold transition-colors {{ request()->routeIs('dashboard') ? 'text-purple-600' : 'text-slate-500 hover:text-slate-800' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Dasbor</span>
            </a>
            <a href="{{ route('materials.index') }}" class="flex flex-col items-center gap-1 text-[10px] font-semibold transition-colors {{ request()->routeIs('materials.*') ? 'text-purple-600' : 'text-slate-500 hover:text-slate-800' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                <span>Materi</span>
            </a>
            <a href="{{ route('generations.index') }}" class="flex flex-col items-center gap-1 text-[10px] font-semibold transition-colors {{ request()->routeIs('generations.*') ? 'text-purple-600' : 'text-slate-500 hover:text-slate-800' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span>Buat Soal</span>
            </a>
            <a href="{{ route('question-sets.index') }}" class="flex flex-col items-center gap-1 text-[10px] font-semibold transition-colors {{ request()->routeIs('question-sets.*') ? 'text-purple-600' : 'text-slate-500 hover:text-slate-800' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                <span>Bank Soal</span>
            </a>
            <a href="{{ route('account.subscription.show') }}" class="flex flex-col items-center gap-1 text-[10px] font-semibold transition-colors {{ request()->routeIs('account.subscription.*') ? 'text-purple-600' : 'text-slate-500 hover:text-slate-800' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                <span>Langganan</span>
            </a>
        </nav>
    @endauth
    @stack('scripts')
</body>
</html>
