<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class InvalidEntitlementException extends RuntimeException
{
    public function __construct(
        string $message = 'The account entitlement cannot be resolved.',
        private readonly ?int $userId = null,
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
        ], fn (mixed $value): bool => $value !== null);
    }
}
