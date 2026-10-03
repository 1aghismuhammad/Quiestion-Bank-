@extends('layouts.app')

@section('title', 'Lengkapi Profil')

@section('content')
    <div class="profile-setup-page">
        <x-ui.page-header>
            Lengkapi profil
            <x-slot:supporting>
                Nomor WhatsApp dipakai untuk notifikasi.
            </x-slot:supporting>
        </x-ui.page-header>

        <x-ui.panel class="profile-setup-panel">
            <form method="POST" action="{{ route('profile.setup.store') }}">
                @csrf

                <x-ui.text-input
                    name="phone_number"
                    id="phone_number"
                    type="tel"
                    label="Nomor WhatsApp"
                    :value="old('phone_number', auth()->user()->phone_number)"
                    placeholder="081234567890"
                    required
                    autofocus
                />

                <x-ui.button type="submit">Simpan dan lanjutkan</x-ui.button>
            </form>
        </x-ui.panel>
    </div>
@endsection
