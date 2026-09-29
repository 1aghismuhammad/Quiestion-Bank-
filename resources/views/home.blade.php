@extends('layouts.app')

@section('title', 'AI Question Bank')

@section('content')
    <div class="page-reading">
        <x-ui.page-header>
            Masuk ke AI Question Bank
            <x-slot:supporting>
                Buat bank soal dari materi pembelajaran.
            </x-slot:supporting>
        </x-ui.page-header>

        @auth
            <x-ui.button href="{{ route('dashboard') }}">Buka dasbor</x-ui.button>
        @else
            <x-ui.button href="{{ route('login') }}">Login dengan Google</x-ui.button>
        @endauth
    </div>
@endsection
