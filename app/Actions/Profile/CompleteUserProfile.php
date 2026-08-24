<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteUserProfile
{
    public function handle(User $user, string $phoneNumber): WhatsAppContact
    {
        $normalizedPhoneNumber = $this->normalizeIndonesianPhoneNumber($phoneNumber);

        return DB::transaction(function () use ($user, $normalizedPhoneNumber): WhatsAppContact {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            if ($lockedUser->whatsappContact()->exists()) {
                throw ValidationException::withMessages([
                    'phone_number' => 'Profil sudah dilengkapi.',
                ]);
            }

            try {
                $contact = $lockedUser->whatsappContact()->create([
                    'phone_number' => $normalizedPhoneNumber,
                    'country_code' => '+62',
                    'is_verified' => false,
                    'marketing_consent' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'phone_number' => 'Nomor telepon sudah digunakan atau profil telah dilengkapi.',
                ]);
            }

            $lockedUser->update([
                'phone_number' => $normalizedPhoneNumber,
                'phone_verified_at' => null,
                'marketing_consent' => false,
            ]);

            return $contact;
        });
    }

    private function normalizeIndonesianPhoneNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '62')) {
            $digits = '62'.$digits;
        }

        return '+'.$digits;
    }
}
