<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class AmbiguousUpgradeRequestsException extends RuntimeException
{
    public function __construct(
        string $message = 'The upgrade request cannot be resolved.',
        private readonly ?int $userId = null,
        private readonly ?int $pendingCount = null,
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
            'pending_count' => $this->pendingCount,
        ], fn (mixed $value): bool => $value !== null);
    }
}
