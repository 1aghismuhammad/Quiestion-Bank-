<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGenerationAttempt>
 */
class AiGenerationAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_id' => AiGeneration::factory(),
            'attempt_number' => 1,
            'provider' => 'google_gemini',
            'model' => 'gemini-3.5-flash-lite',
            'purpose' => GenerationAttemptPurpose::INITIAL,
            'prompt_version' => 'mcq-v1',
            'requested_count' => 5,
            'accepted_count' => 0,
            'status' => GenerationAttemptStatus::STARTED,
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'latency_ms' => null,
            'finish_reason' => null,
            'safe_error_code' => null,
            'started_at' => now(),
            'finished_at' => null,
        ];
    }
}
