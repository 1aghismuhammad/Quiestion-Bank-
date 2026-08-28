<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'AI Question Bank')</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, sans-serif;
            color: #172033;
            background: #f4f7fb;
        }

        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; }
        a { color: inherit; }
        .container { width: min(1080px, calc(100% - 32px)); margin: 0 auto; }
        .nav { background: #ffffff; border-bottom: 1px solid #dce3ee; }
        .nav-inner { min-height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .brand { font-size: 18px; font-weight: 750; text-decoration: none; }
        .nav-actions { display: flex; align-items: center; gap: 12px; }
        .page { padding: 40px 0; }
        .card { background: #ffffff; border: 1px solid #dce3ee; border-radius: 14px; padding: 24px; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 18px; border: 0; border-radius: 9px; background: #2356d8; color: #ffffff; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button-secondary { background: #e9eef8; color: #172033; }
        .muted { color: #667085; }
        .label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 700; }
        .input { width: 100%; min-height: 44px; border: 1px solid #bac5d6; border-radius: 9px; padding: 10px 12px; font: inherit; }
        .alert { margin-bottom: 20px; border-radius: 9px; padding: 12px 14px; }
        .alert-success { color: #155e3b; background: #e8f8ef; }
        .alert-error { color: #9f2424; background: #fdecec; }
        .error-text { margin-top: 6px; color: #b42318; font-size: 14px; }
        .stat { font-size: 34px; font-weight: 800; margin: 6px 0 0; }
        .placeholder { min-height: 120px; display: flex; flex-direction: column; justify-content: space-between; }
        .status { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #e8f8ef; color: #155e3b; font-size: 13px; font-weight: 700; }
        .status-warn { background: #fff4d6; color: #7a5400; }
        .status-error { background: #fdecec; color: #9f2424; }
        .status-muted { background: #e9eef8; color: #344054; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #dce3ee; vertical-align: top; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .button-danger { background: #b42318; color: #ffffff; }
        textarea.input { min-height: 180px; resize: vertical; }
        .content-block { white-space: pre-wrap; background: #f4f7fb; border-radius: 9px; padding: 16px; }
        .field-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        h1, h2, p { margin-top: 0; }
    </style>
</head>
<body>
    <nav class="nav">
        <div class="container nav-inner">
            <a class="brand" href="{{ route('home') }}">AI Question Bank</a>

            @auth
                <div class="nav-actions">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <a href="{{ route('materials.index') }}">Materi</a>
                    <a href="{{ route('account.subscription.show') }}">Langganan</a>
                    <span class="muted">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="button button-secondary" type="submit">Logout</button>
                    </form>
                </div>
            @endauth
        </div>
    </nav>

    <main class="container page">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
