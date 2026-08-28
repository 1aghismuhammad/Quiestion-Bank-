<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class AmbiguousEntitlementException extends RuntimeException
{
    public function __construct(
        string $message = 'The account entitlement cannot be resolved.',
        private readonly ?int $userId = null,
        private readonly ?int $effectiveCount = null,
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
            'effective_count' => $this->effectiveCount,
        ], fn (mixed $value): bool => $value !== null);
    }
}
