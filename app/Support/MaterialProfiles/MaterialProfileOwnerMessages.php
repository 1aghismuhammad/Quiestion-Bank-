<?php

declare(strict_types=1);

namespace App\Support\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;

/**
 * The single mapping from domain error codes to owner-facing Indonesian text.
 *
 * Every branch returns a fixed string. Raw exception messages, provider bodies,
 * model names, attempt internals, and token or lock details never reach here.
 */
final class MaterialProfileOwnerMessages
{
    public const GENERIC = 'Analisis profil materi gagal. Silakan coba lagi.';

    public static function forException(MaterialProfileRejectedException $exception): string
    {
        return self::forCode($exception->errorCode);
    }

    public static function forErrorCode(?string $code): string
    {
        $errorCode = $code === null ? null : MaterialProfileErrorCode::tryFrom($code);

        return $errorCode === null ? self::GENERIC : self::forCode($errorCode);
    }

    /**
     * Owner JSON may only carry this small public set. Internal authority and
     * concurrency codes stay in the database and map to `provider_failed`.
     */
    public static function publicCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $errorCode = MaterialProfileErrorCode::tryFrom($code);

        if ($errorCode === null) {
            return MaterialProfileErrorCode::ProviderFailed->value;
        }

        return match ($errorCode) {
            MaterialProfileErrorCode::DuplicateWorker,
            MaterialProfileErrorCode::NotNextStep,
            MaterialProfileErrorCode::Revoked,
            MaterialProfileErrorCode::ValidationFailed,
            MaterialProfileErrorCode::StepAborted => MaterialProfileErrorCode::ProviderFailed->value,
            default => $errorCode->value,
        };
    }

    public static function forCode(MaterialProfileErrorCode $errorCode): string
    {
        return match ($errorCode) {
            MaterialProfileErrorCode::InFlightExists => 'Analisis profil materi ini masih berjalan. Tunggu sampai selesai sebelum memulai lagi.',
            MaterialProfileErrorCode::ThrottleExceeded => 'Batas tiga analisis profil per jam sudah tercapai. Silakan coba lagi dalam satu jam.',
            MaterialProfileErrorCode::MaterialIneligible => 'Materi ini belum memenuhi syarat untuk dianalisis. Pastikan materi sudah siap dan tidak diarsipkan.',
            MaterialProfileErrorCode::MaterialEmpty => 'Materi ini belum memiliki konten teks untuk dianalisis.',
            MaterialProfileErrorCode::MaterialTooLarge => 'Materi ini terlalu panjang untuk dianalisis.',
            MaterialProfileErrorCode::HashMismatch => 'Konten materi berubah sehingga analisis dihentikan. Jalankan analisis baru untuk konten terbaru.',
            MaterialProfileErrorCode::StaleRecovery => 'Analisis profil tidak selesai tepat waktu dan dihentikan. Silakan jalankan analisis baru.',
            MaterialProfileErrorCode::QueuedAbandoned => 'Analisis profil tidak pernah mulai diproses dan dihentikan. Silakan jalankan analisis baru.',
            MaterialProfileErrorCode::ProviderFailed => 'Analisis profil materi gagal diproses. Silakan jalankan analisis baru.',
            MaterialProfileErrorCode::StepAborted,
            MaterialProfileErrorCode::NotNextStep,
            MaterialProfileErrorCode::Revoked,
            MaterialProfileErrorCode::DuplicateWorker,
            MaterialProfileErrorCode::ValidationFailed => self::GENERIC,
        };
    }
}
