@extends('layouts.app')

@section('title', 'Dasbor')

@section('content')
    <x-ui.page-header>
        Dasbor
        <x-slot:supporting>
            Mulai dari materi untuk membuat soal.
        </x-slot:supporting>
    </x-ui.page-header>

    <div class="action-stack">
        <x-ui.button href="{{ route('materials.index') }}">Kelola materi</x-ui.button>
        <x-ui.button variant="secondary" href="{{ route('question-sets.index') }}">Bank soal</x-ui.button>
        <x-ui.button variant="secondary" href="{{ route('account.subscription.show') }}">Langganan</x-ui.button>
        @if ($user->hasRole(\App\Enums\RoleName::ADMIN))
            <x-ui.button variant="tertiary" href="{{ route('admin.dashboard') }}">Dasbor admin</x-ui.button>
        @endif
    </div>
@endsection
