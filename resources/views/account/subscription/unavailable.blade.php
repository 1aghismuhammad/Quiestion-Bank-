@extends('layouts.app')

@section('title', 'Langganan')

@section('content')
    <div class="subscription-page subscription-page--unavailable">
        <x-ui.page-header>
            Langganan
        </x-ui.page-header>

        <x-ui.alert>
            Paket langganan tidak dapat ditampilkan saat ini. Silakan coba lagi nanti.
        </x-ui.alert>
    </div>
@endsection
