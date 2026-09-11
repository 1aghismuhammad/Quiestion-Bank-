<?php

declare(strict_types=1);

namespace App\Support\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;

final class BlueprintOwnerMessages
{
    public const GENERIC = 'Kisi-kisi tidak dapat diproses. Silakan coba lagi.';

    public static function forException(BlueprintRejectedException $exception): string
    {
        return $exception->errorCode->userMessage();
    }

    public static function forCode(?string $code): string
    {
        $error = $code === null ? null : BlueprintErrorCode::tryFrom($code);

        return $error?->userMessage() ?? self::GENERIC;
    }
}
