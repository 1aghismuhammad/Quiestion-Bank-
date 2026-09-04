<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

/**
 * Sanitized pre-call provider identity. Domain Actions use this to open an
 * Attempt without knowing which concrete adapter is bound.
 */
final readonly class ProfileProviderIdentity
{
    public function __construct(public string $name) {}
}
