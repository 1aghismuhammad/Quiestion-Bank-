@php
    $usage = $usage ?? null;
@endphp

@if ($usage)
    <div class="card" style="margin-bottom: 20px;">
        <h2>Kuota generasi</h2>
        <p><strong>Terpakai:</strong> {{ $usage->consumed }}</p>
        <p><strong>Diproses:</strong> {{ $usage->reserved }}</p>
        <p><strong>Tersedia:</strong> {{ $usage->displayedAvailable() }}</p>
    </div>
@endif
