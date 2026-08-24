@extends('layouts.app')

@section('title', 'Lengkapi Profil')

@section('content')
    <div class="card" style="max-width: 560px; margin: 24px auto;">
        <p class="muted">LANGKAH TERAKHIR</p>
        <h1>Lengkapi nomor WhatsApp</h1>
        <p class="muted">
            Nomor telepon wajib diisi sebelum Anda dapat mengakses dashboard.
            Format Indonesia akan otomatis dinormalisasi ke +62.
        </p>

        <form method="POST" action="{{ route('profile.setup.store') }}">
            @csrf

            <label class="label" for="phone_number">Nomor telepon</label>
            <input
                class="input"
                id="phone_number"
                name="phone_number"
                type="tel"
                value="{{ old('phone_number', auth()->user()->phone_number) }}"
                placeholder="081234567890"
                required
                autofocus
            >

            @error('phone_number')
                <div class="error-text">{{ $message }}</div>
            @enderror

            <button class="button" style="margin-top: 20px;" type="submit">
                Simpan dan lanjutkan
            </button>
        </form>
    </div>
@endsection
