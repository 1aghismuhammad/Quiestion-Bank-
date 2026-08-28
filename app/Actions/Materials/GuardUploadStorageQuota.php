<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Data\Subscriptions\ResolvedEntitlement;
use App\Models\User;
use App\Services\Materials\MaterialUsageCalculator;
use Illuminate\Validation\ValidationException;

class GuardUploadStorageQuota
{
    public function __construct(private MaterialUsageCalculator $usageCalculator) {}

    public function handle(User $user, int $incomingBytes, ResolvedEntitlement $entitlement): void
    {
        $usage = $this->usageCalculator->usageInBytes($user);

        if (($usage + $incomingBytes) > $entitlement->storageLimitBytes()) {
            throw ValidationException::withMessages([
                'file' => 'Penyimpanan paket Anda tidak mencukupi untuk file ini.',
            ]);
        }
    }
}
