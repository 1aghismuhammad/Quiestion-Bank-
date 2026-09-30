@php
    $groundingLabel = match ($status ?? null) {
        null, '' => 'Belum dicocokkan',
        'queued' => 'Menunggu verifikasi',
        'processing' => 'Sedang dicocokkan',
        'ready' => 'Pencocokan selesai',
        'failed' => 'Gagal',
        default => 'Status tidak dikenali',
    };
@endphp
{{ $groundingLabel }}
