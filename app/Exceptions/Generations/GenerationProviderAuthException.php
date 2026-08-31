<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use App\Enums\GenerationErrorCode;
use RuntimeException;

class GenerationProviderAuthException extends RuntimeException
{
    public function __construct(
        string $message = 'The generation provider rejected the credentials.',
        private readonly ?int $generationId = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): GenerationErrorCode
    {
        return GenerationErrorCode::Auth;
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
