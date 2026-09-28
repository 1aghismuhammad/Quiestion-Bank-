<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'AI Question Bank')</title>
    <style>[hidden] { display: none !important; }</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main">Langsung ke isi</a>

    <header class="shell-header">
        <div class="container shell-bar">
            <a class="shell-brand" href="{{ route('home') }}">AI Question Bank</a>

            @auth
                <nav class="shell-nav" aria-label="Utama">
                    <a href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>Dasbor</a>
                    <a href="{{ route('materials.index') }}" @if (request()->routeIs('materials.index')) aria-current="page" @endif>Materi</a>
                    <a href="{{ route('generations.index') }}" @if (request()->routeIs('generations.index')) aria-current="page" @endif>Pembuatan soal</a>
                    <a href="{{ route('question-sets.index') }}" @if (request()->routeIs('question-sets.index')) aria-current="page" @endif>Bank soal</a>
                    <a href="{{ route('account.subscription.show') }}" @if (request()->routeIs('account.subscription.show')) aria-current="page" @endif>Langganan</a>
                    <span class="shell-user">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-ui.button variant="secondary" type="submit">Keluar</x-ui.button>
                    </form>
                </nav>
            @endauth
        </div>
    </header>

    <main id="main" class="container page" tabindex="-1">
        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
