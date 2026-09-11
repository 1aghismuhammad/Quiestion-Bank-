<?php

declare(strict_types=1);

namespace App\Support\QuestionBlueprints;

use App\Enums\BlueprintAttemptErrorCode;
use App\Exceptions\QuestionBlueprints\BlueprintProviderException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderPermanentException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderTransientException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use PDOException;
use Throwable;

/**
 * Sanitized mapping for catchable Throwables that escape the Blueprint provider.
 *
 * Raw messages, stacks, bodies, prompts, URLs, and credentials are discarded.
 * Database exceptions are rethrown so a failed write cannot look successful.
 */
final class BlueprintUnexpectedProviderFailure
{
    public static function classify(Throwable $exception): BlueprintProviderException
    {
        if ($exception instanceof BlueprintProviderException) {
            return $exception;
        }

        if (self::isDatabaseException($exception)) {
            throw $exception;
        }

        if ($exception instanceof ConnectionException) {
            return new BlueprintProviderTransientException(
                BlueprintAttemptErrorCode::ProviderTimeout,
                'The blueprint provider failed transiently.',
            );
        }

        return new BlueprintProviderPermanentException(
            BlueprintAttemptErrorCode::ProviderHttp,
            'The blueprint provider failed unexpectedly.',
        );
    }

    private static function isDatabaseException(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            || $exception instanceof PDOException;
    }
}
