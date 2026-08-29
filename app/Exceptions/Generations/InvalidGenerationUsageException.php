<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use RuntimeException;

class InvalidGenerationUsageException extends RuntimeException
{
    public function __construct(
        string $message = 'The generation usage cannot be finalized.',
        private readonly ?int $userId = null,
        private readonly ?int $generationId = null,
        private readonly ?int $usageId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, int>
     */
    public function context(): array
    {
        return array_filter([
            'user_id' => $this->userId,
            'generation_id' => $this->generationId,
            'usage_id' => $this->usageId,
        ], fn (mixed $value): bool => $value !== null);
    }
}
