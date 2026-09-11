<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintErrorCode: string
{
    case MaterialIneligible = 'material_ineligible';
    case ProfileRequired = 'profile_required';
    case ProfileNotReady = 'profile_not_ready';
    case ProfileStale = 'profile_stale';
    case DraftExists = 'draft_exists';
    case ConfirmedImmutable = 'confirmed_immutable';
    case ValidationFailed = 'validation_failed';
    case InFlightExists = 'in_flight_exists';
    case ThrottleExceeded = 'throttle_exceeded';
    case ProviderFailed = 'provider_failed';
    case StaleRecovery = 'stale_recovery';
    case QueuedAbandoned = 'queued_abandoned';
    case HashMismatch = 'hash_mismatch';
    case Revoked = 'revoked';
    case DuplicateWorker = 'duplicate_worker';
    case RowsNotEmpty = 'rows_not_empty';
    case ContextRequired = 'context_required';
    case RequestTooLarge = 'request_too_large';

    public function userMessage(): string
    {
        return match ($this) {
            self::MaterialIneligible => 'Materi belum memenuhi syarat untuk kisi-kisi.',
            self::ProfileRequired, self::ProfileNotReady => 'Profil materi yang siap diperlukan sebelum kisi-kisi dapat dibuat.',
            self::ProfileStale => 'Profil materi tidak sesuai dengan konten terbaru.',
            self::DraftExists => 'Seri ini sudah memiliki draf kisi-kisi.',
            self::ConfirmedImmutable => 'Kisi-kisi yang sudah dikonfirmasi tidak dapat diubah.',
            self::InFlightExists => 'Pengisian AI kisi-kisi untuk materi ini sedang berjalan.',
            self::ThrottleExceeded => 'Batas tiga pengisian AI kisi-kisi per jam telah tercapai.',
            self::ProviderFailed => 'Pengisian AI kisi-kisi gagal. Silakan coba lagi.',
            self::StaleRecovery, self::QueuedAbandoned => 'Pengisian AI kisi-kisi tidak selesai tepat waktu.',
            self::RowsNotEmpty => 'Draf ini sudah memiliki baris. Buat seri baru untuk pengisian AI.',
            self::ContextRequired => 'Setiap baris kisi-kisi wajib memiliki sumber konteks dari profil materi.',
            self::RequestTooLarge => 'Konteks profil terlalu besar untuk pengisian AI yang dibatasi.',
            default => 'Kisi-kisi tidak dapat diproses. Periksa isian lalu coba lagi.',
        };
    }

    public function publicCode(): string
    {
        return match ($this) {
            self::Revoked, self::DuplicateWorker, self::ValidationFailed, self::HashMismatch => self::ProviderFailed->value,
            default => $this->value,
        };
    }
}
