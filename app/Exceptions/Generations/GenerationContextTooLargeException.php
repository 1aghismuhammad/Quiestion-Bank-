<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use App\Enums\GenerationErrorCode;
use RuntimeException;

class GenerationContextTooLargeException extends RuntimeException
{
    public function __construct(
        string $message = 'The material exceeds the generation budget.',
        private readonly ?int $generationId = null,
        private readonly GenerationErrorCode $errorCode = GenerationErrorCode::MaterialTooLarge,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): GenerationErrorCode
    {
        return $this->errorCode;
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
