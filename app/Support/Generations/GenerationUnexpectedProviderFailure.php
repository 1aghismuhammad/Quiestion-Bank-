<?php

declare(strict_types=1);

namespace App\Support\Generations;

use App\Enums\GenerationErrorCode;
use App\Exceptions\Generations\GenerationConfigurationException;
use App\Exceptions\Generations\GenerationMalformedResponseException;
use App\Exceptions\Generations\GenerationProviderAuthException;
use App\Exceptions\Generations\GenerationProviderPermanentException;
use App\Exceptions\Generations\GenerationProviderTransientException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Sanitized mapping for catchable Throwables that escape the generation provider.
 *
 * Raw messages, stacks, bodies, prompts, URLs, and credentials are discarded.
 * Database exceptions are rethrown so a failed write cannot look successful.
 */
final class GenerationUnexpectedProviderFailure
{
    public static function classify(Throwable $exception): RuntimeException
    {
        if ($exception instanceof GenerationProviderTransientException
            || $exception instanceof GenerationProviderAuthException
            || $exception instanceof GenerationProviderPermanentException
            || $exception instanceof GenerationConfigurationException
            || $exception instanceof GenerationMalformedResponseException) {
            return $exception;
        }

        if (self::isDatabaseException($exception)) {
            throw $exception;
        }

        if ($exception instanceof ConnectionException) {
            return new GenerationProviderTransientException(
                GenerationErrorCode::ProviderTimeout,
                'The generation provider failed transiently.',
            );
        }

        return new GenerationProviderPermanentException(
            'The generation provider failed unexpectedly.',
            GenerationErrorCode::JobFailed,
        );
    }

    private static function isDatabaseException(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            || $exception instanceof PDOException;
    }
}
