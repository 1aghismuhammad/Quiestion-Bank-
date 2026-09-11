<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationRunErrorCode: string
{
    case MaterialIneligible = 'material_ineligible';
    case ProfileRequired = 'profile_required';
    case ProfileStale = 'profile_stale';
    case BlueprintNotConfirmed = 'blueprint_not_confirmed';
    case BlueprintStale = 'blueprint_stale';
    case QuotaInsufficient = 'quota_insufficient';
    case IdempotencyConflict = 'idempotency_conflict';
    case SpanUnavailable = 'span_unavailable';
    case HashMismatch = 'hash_mismatch';
    case ValidationFailed = 'validation_failed';
    case RunNotFailed = 'run_not_failed';

    public function userMessage(): string
    {
        return match ($this) {
            self::MaterialIneligible => 'Materi belum memenuhi syarat untuk generate soal.',
            self::ProfileRequired => 'Profil materi yang siap diperlukan sebelum generasi dapat dimulai.',
            self::ProfileStale, self::BlueprintStale, self::HashMismatch => 'Konteks materi tidak lagi cocok. Mulai generasi baru.',
            self::BlueprintNotConfirmed => 'Hanya kisi-kisi yang sudah dikonfirmasi yang dapat dipakai untuk generasi.',
            self::QuotaInsufficient => 'Kuota generasi paket Anda tidak mencukupi.',
            self::IdempotencyConflict => 'Permintaan generasi tidak dapat diulang dengan data yang berbeda.',
            self::SpanUnavailable => 'Konteks materi tidak memadai untuk generasi.',
            self::RunNotFailed => 'Hanya generasi yang gagal yang dapat dicoba ulang.',
            default => 'Generasi tidak dapat dimulai. Silakan coba lagi.',
        };
    }
}
