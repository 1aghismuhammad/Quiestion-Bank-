<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialProfileStep>
 */
class MaterialProfileStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_version_id' => MaterialProfileVersion::factory(),
            'purpose' => MaterialProfileStepPurpose::REDUCE,
            'step_index' => 0,
            'profile_chunk_id' => null,
            'status' => MaterialProfileStepStatus::QUEUED,
            'workflow_token' => fn (array $attributes): string => (string) MaterialProfileVersion::query()
                ->whereKey($attributes['profile_version_id'])
                ->value('workflow_token'),
            'step_execution_token' => null,
            'step_queued_at' => null,
            'claimed_at' => null,
            'heartbeat_at' => null,
            'lease_expires_at' => null,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    public function map(): static
    {
        return $this->state(fn (): array => [
            'purpose' => MaterialProfileStepPurpose::MAP,
            'step_index' => 0,
        ]);
    }

    public function reduce(): static
    {
        return $this->state(fn (): array => [
            'purpose' => MaterialProfileStepPurpose::REDUCE,
            'step_index' => 0,
            'profile_chunk_id' => null,
        ]);
    }
}
