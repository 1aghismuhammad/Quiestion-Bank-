<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WhatsAppConfirmationUrl
{
    public function isConfigured(): bool
    {
        return $this->digits() !== null;
    }

    public function digits(): ?string
    {
        $raw = preg_replace('/\s+/', '', (string) config('subscriptions.whatsapp_number')) ?? '';

        if ($raw === '' || preg_match('/^[1-9][0-9]{7,14}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    public function build(SubscriptionUpgradeRequest $request, User $user): string
    {
        $digits = $this->digits();

        if ($digits === null) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Konfirmasi WhatsApp belum dikonfigurasi.',
            ]);
        }

        $amount = 'Rp'.number_format($request->price_amount, 0, ',', '.');

        $message = implode("\n", [
            'Konfirmasi pembayaran AI Question Bank',
            'Ref: '.$request->reference_code,
            'Nama: '.$user->name,
            'Email: '.$user->email,
            'Penawaran: '.$request->offer_name,
            'Durasi: '.$request->duration_months.' bulan',
            'Jumlah: '.$amount,
        ]);

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }
}
