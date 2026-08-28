<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class InvalidUpgradeRequestException extends RuntimeException
{
    public function __construct(
        string $message = 'The upgrade request cannot be processed.',
        private readonly ?int $userId = null,
        private readonly ?int $upgradeRequestId = null,
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
            'upgrade_request_id' => $this->upgradeRequestId,
        ], fn (mixed $value): bool => $value !== null);
    }
}
