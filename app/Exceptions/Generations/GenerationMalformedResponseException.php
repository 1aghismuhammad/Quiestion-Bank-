<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use App\Enums\GenerationErrorCode;
use RuntimeException;

class GenerationMalformedResponseException extends RuntimeException
{
    public function __construct(
        string $message = 'The generation provider returned malformed output.',
        private readonly ?int $generationId = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): GenerationErrorCode
    {
        return GenerationErrorCode::MalformedOutput;
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
