<nav class="admin-nav" aria-label="Admin">
    <a href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>Dasbor</a>
    <a href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>Pengguna</a>
    <a href="{{ route('admin.subscription-upgrades.index') }}" @if(request()->routeIs('admin.subscription-upgrades.*')) aria-current="page" @endif>Verifikasi</a>
</nav>