<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\GenerationErrorCode;
use App\Exceptions\Generations\GenerationConfigurationException;
use App\Support\Generations\ProviderAttemptBudget;

class GeminiModelSelector
{
    public function modelForAttempt(int $attemptNumber, ?GenerationErrorCode $previousError = null): string
    {
        $primary = $this->requiredModel('generation.primary_model');

        if ($attemptNumber < ProviderAttemptBudget::MAX) {
            return $primary;
        }

        $fallback = config('generation.fallback_model');

        if (
            is_string($fallback)
            && $fallback !== ''
            && $previousError?->isFallbackEligible()
        ) {
            return $fallback;
        }

        return $primary;
    }

    private function requiredModel(string $key): string
    {
        $model = config($key);

        if (! is_string($model) || $model === '') {
            throw new GenerationConfigurationException('The generation model is not configured.');
        }

        return $model;
    }
}
