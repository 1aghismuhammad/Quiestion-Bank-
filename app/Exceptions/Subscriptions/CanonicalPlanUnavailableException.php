<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class CanonicalPlanUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'The canonical Free plan is unavailable.',
        private readonly ?string $codeValue = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return array_filter([
            'code' => $this->codeValue,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
