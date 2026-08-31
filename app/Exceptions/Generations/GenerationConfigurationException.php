<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use App\Enums\GenerationErrorCode;
use RuntimeException;

class GenerationConfigurationException extends RuntimeException
{
    public function __construct(
        string $message = 'Generation is not configured.',
        private readonly ?int $generationId = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): GenerationErrorCode
    {
        return GenerationErrorCode::Configuration;
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
