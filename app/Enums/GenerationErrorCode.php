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
            self::InvalidQuestionCount => true,
            default => false,
        };
    }
}
