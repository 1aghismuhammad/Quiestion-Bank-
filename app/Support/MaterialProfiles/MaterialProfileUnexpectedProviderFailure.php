<?php

declare(strict_types=1);

namespace App\Support\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderPermanentException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderTransientException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use PDOException;
use Throwable;

/**
 * Sanitized mapping for catchable Throwables that escape the provider adapter.
 *
 * The raw message, stack, provider body, prompt, URL, and credentials are
 * discarded. Only an allow-listed Attempt error code reaches persistence.
 *
 * Database exceptions are rethrown so a failed write cannot look successful.
 */
final class MaterialProfileUnexpectedProviderFailure
{
    public static function classify(Throwable $exception): MaterialProfileProviderException
    {
        if ($exception instanceof MaterialProfileProviderException) {
            return $exception;
        }

        if (self::isDatabaseException($exception)) {
            throw $exception;
        }

        if (self::isRecognizedTransient($exception)) {
            return new MaterialProfileProviderTransientException(
                MaterialProfileAttemptErrorCode::ProviderTimeout,
                'The material profile provider failed transiently.',
            );
        }

        return new MaterialProfileProviderPermanentException(
            MaterialProfileAttemptErrorCode::ProviderHttp,
            'The material profile provider failed unexpectedly.',
        );
    }

    private static function isDatabaseException(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            || $exception instanceof PDOException;
    }

    private static function isRecognizedTransient(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException;
    }
}
