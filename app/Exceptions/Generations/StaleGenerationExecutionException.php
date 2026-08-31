<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use RuntimeException;

class StaleGenerationExecutionException extends RuntimeException
{
    public function __construct(
        string $message = 'This generation execution no longer owns the generation.',
        private readonly ?int $generationId = null,
        private readonly ?string $executionToken = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, int|string>
     */
    public function context(): array
    {
        return array_filter([
            'generation_id' => $this->generationId,
            'execution_token' => $this->executionToken,
        ], fn (mixed $value): bool => $value !== null);
    }
}
