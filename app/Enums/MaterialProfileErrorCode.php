<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileErrorCode: string
{
    case InFlightExists = 'in_flight_exists';
    case MaterialIneligible = 'material_ineligible';
    case MaterialEmpty = 'material_empty';
    case MaterialTooLarge = 'material_too_large';
    case StaleRecovery = 'stale_recovery';
    case QueuedAbandoned = 'queued_abandoned';
    case StepAborted = 'step_aborted';
    case HashMismatch = 'hash_mismatch';
    case NotNextStep = 'not_next_step';
    case Revoked = 'revoked';
    case DuplicateWorker = 'duplicate_worker';
    case ValidationFailed = 'validation_failed';

    public function userMessage(): string
    {
        return match ($this) {
            self::InFlightExists => 'Analisis profil materi masih berjalan.',
            self::MaterialIneligible => 'Materi belum memenuhi syarat untuk dianalisis.',
            self::MaterialEmpty => 'Materi tidak memiliki konten.',
            self::MaterialTooLarge => 'Materi terlalu panjang untuk dianalisis.',
            self::StaleRecovery => 'Analisis profil tidak selesai tepat waktu.',
            self::QueuedAbandoned => 'Analisis profil tidak dimulai tepat waktu.',
            self::HashMismatch => 'Konten materi berubah sebelum analisis selesai.',
            default => 'Analisis profil materi gagal.',
        };
    }
}
