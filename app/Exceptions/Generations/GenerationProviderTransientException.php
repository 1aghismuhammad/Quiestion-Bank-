<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use App\Enums\GenerationErrorCode;
use RuntimeException;

class GenerationProviderTransientException extends RuntimeException
{
    public function __construct(
        private readonly GenerationErrorCode $errorCode = GenerationErrorCode::ProviderUnavailable,
        string $message = 'The generation provider is temporarily unavailable.',
        private readonly ?int $generationId = null,
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): GenerationErrorCode
    {
        return $this->errorCode;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * @return array<string, int>
     */
    public function context(): array
    {
        return array_filter([
            'generation_id' => $this->generationId,
        ], fn (mixed $value): bool => $value !== null);
    }
}
