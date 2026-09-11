<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationErrorCode: string
{
    case MaterialTooLarge = 'material_too_large';
    case MaterialEmpty = 'material_empty';
    case ProviderTimeout = 'provider_timeout';
    case ProviderRateLimited = 'provider_rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case MalformedOutput = 'malformed_output';
    case IncompleteOutput = 'incomplete_output';
    case Configuration = 'configuration';
    case Auth = 'auth';
    case UnsupportedQuestionType = 'unsupported_question_type';
    case InvalidQuestionCount = 'invalid_question_count';
    case MissingOutputLanguage = 'missing_output_language';
    case UnsupportedOutputLanguage = 'unsupported_output_language';
    case JobFailed = 'job_failed';
    case StaleRecovery = 'stale_recovery';
    case RunAborted = 'run_aborted';
    case HashMismatch = 'hash_mismatch';
    case BlueprintStale = 'blueprint_stale';
    case DuplicateStem = 'duplicate_stem';
    case TopologyInvalid = 'topology_invalid';

    public function userMessage(): string
    {
        return match ($this) {
            self::MaterialTooLarge => 'Materi terlalu panjang untuk digenerate.',
            self::MaterialEmpty => 'Materi tidak memiliki konten.',
            self::MissingOutputLanguage, self::UnsupportedOutputLanguage => 'Bahasa keluaran tidak valid.',
            self::Configuration => 'Layanan generasi belum dikonfigurasi.',
            self::Auth => 'Layanan generasi gagal diautentikasi.',
            self::UnsupportedQuestionType => 'Tipe soal ini belum didukung.',
            self::InvalidQuestionCount => 'Jumlah soal tidak valid.',
            self::MalformedOutput, self::IncompleteOutput => 'Gagal menghasilkan soal yang lengkap.',
            self::StaleRecovery => 'Generasi tidak selesai tepat waktu. Silakan coba lagi.',
            self::RunAborted => 'Generasi dihentikan karena langkah lain gagal.',
            self::HashMismatch, self::BlueprintStale => 'Konteks materi tidak lagi cocok. Mulai generasi baru.',
            self::DuplicateStem, self::TopologyInvalid => 'Gagal menghasilkan soal yang lengkap.',
            default => 'Gagal menghasilkan soal. Silakan coba lagi.',
        };
    }

    public function isFallbackEligible(): bool
    {
        return match ($this) {
            self::ProviderTimeout,
            self::ProviderRateLimited,
            self::ProviderUnavailable,
            self::MalformedOutput,
            self::IncompleteOutput => true,
            default => false,
        };
    }

    public function isPermanent(): bool
    {
        return match ($this) {
            self::Configuration,
            self::Auth,
            self::MaterialTooLarge,
            self::MaterialEmpty,
            self::MissingOutputLanguage,
            self::UnsupportedOutputLanguage,
            self::UnsupportedQuestionType,
            self::InvalidQuestionCount,
            self::StaleRecovery,
            self::RunAborted,
            self::HashMismatch,
            self::BlueprintStale,
            self::DuplicateStem,
            self::TopologyInvalid => true,
            default => false,
        };
    }
}
