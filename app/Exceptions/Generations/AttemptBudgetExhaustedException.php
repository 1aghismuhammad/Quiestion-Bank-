<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use RuntimeException;

class AttemptBudgetExhaustedException extends RuntimeException
{
    public function __construct(
        string $message = 'The generation provider attempt budget is exhausted.',
        private readonly ?int $generationId = null,
    ) {
        parent::__construct($message);
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
