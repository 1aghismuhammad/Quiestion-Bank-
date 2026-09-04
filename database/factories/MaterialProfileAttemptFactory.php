<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialProfileAttempt>
 */
class MaterialProfileAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_step_id' => MaterialProfileStep::factory(),
            'profile_version_id' => fn (array $attributes): int => (int) MaterialProfileStep::query()
                ->whereKey($attributes['profile_step_id'])
                ->value('profile_version_id'),
            'attempt_number' => 1,
            'provider' => 'fixture',
            'model' => 'fixture-model',
            'prompt_version' => 'profile-b1',
            'purpose' => function (array $attributes): MaterialProfileStepPurpose {
                $purpose = MaterialProfileStep::query()
                    ->whereKey($attributes['profile_step_id'])
                    ->value('purpose');

                return $purpose instanceof MaterialProfileStepPurpose
                    ? $purpose
                    : MaterialProfileStepPurpose::from((string) $purpose);
            },
            'status' => MaterialProfileAttemptStatus::SUCCEEDED,
            'input_tokens' => 10,
            'output_tokens' => 20,
            'total_tokens' => 30,
            'latency_ms' => 5,
            'error_code' => null,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
