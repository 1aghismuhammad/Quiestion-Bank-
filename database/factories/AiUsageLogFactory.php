<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanCode;
use App\Enums\UsageStatus;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageLog>
 */
class AiUsageLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_id' => AiGeneration::factory(),
            'user_id' => fn (array $attributes): int => (int) AiGeneration::query()->findOrFail($attributes['generation_id'])->user_id,
            'plan_id' => fn (): int => (int) Plan::query()->where('code', PlanCode::FREE)->firstOrFail()->plan_id,
            'subscription_id' => null,
            'status' => UsageStatus::RESERVED,
            'window_start' => null,
            'window_end' => null,
            'reserved_at' => now(),
            'finalized_at' => null,
        ];
    }

    public function charged(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UsageStatus::CHARGED,
            'finalized_at' => $attributes['finalized_at'] ?? now(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UsageStatus::RELEASED,
            'finalized_at' => $attributes['finalized_at'] ?? now(),
        ]);
    }
}
