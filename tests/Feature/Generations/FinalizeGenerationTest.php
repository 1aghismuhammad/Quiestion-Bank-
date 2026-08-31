<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\FinalizeGenerationFailure;
use App\Actions\Generations\FinalizeGenerationSuccess;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Models\AiGeneration;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class FinalizeGenerationTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_success_finalizer_completes_and_charges_once(): void
    {
        $generation = $this->processingGeneration(1);
        $token = (string) $generation->execution_token;
        $set = new ValidatedMcqSet([
            ValidatedMcqQuestion::fromArray(GeminiFakeResponses::question('Only')),
        ]);

        $this->app->make(FinalizeGenerationSuccess::class)->handle(
            (int) $generation->generation_id,
            $token,
            $set,
        );
        $this->app->make(FinalizeGenerationSuccess::class)->handle(
            (int) $generation->generation_id,
            $token,
            $set,
        );

        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::CHARGED, $generation->fresh()->usageLog->status);
        $this->assertSame(1, $generation->fresh()->usageLog()->count());
    }

    public function test_failure_after_success_is_rejected(): void
    {
        $generation = $this->processingGeneration(1);
        $token = (string) $generation->execution_token;
        $set = new ValidatedMcqSet([
            ValidatedMcqQuestion::fromArray(GeminiFakeResponses::question('Only')),
        ]);

        $this->app->make(FinalizeGenerationSuccess::class)->handle(
            (int) $generation->generation_id,
            $token,
            $set,
        );

        $this->expectException(InvalidGenerationUsageException::class);
        $this->app->make(FinalizeGenerationFailure::class)->handle(
            (int) $generation->generation_id,
            $token,
            GenerationErrorCode::IncompleteOutput,
        );
    }

    public function test_success_after_failure_is_rejected(): void
    {
        $generation = $this->processingGeneration(1);
        $token = (string) $generation->execution_token;

        $this->app->make(FinalizeGenerationFailure::class)->handle(
            (int) $generation->generation_id,
            $token,
            GenerationErrorCode::IncompleteOutput,
        );

        $this->expectException(InvalidGenerationUsageException::class);
        $this->app->make(FinalizeGenerationSuccess::class)->handle(
            (int) $generation->generation_id,
            $token,
            new ValidatedMcqSet([
                ValidatedMcqQuestion::fromArray(GeminiFakeResponses::question('Only')),
            ]),
        );
    }

    public function test_repeated_failure_is_idempotent(): void
    {
        $generation = $this->processingGeneration(1);
        $token = (string) $generation->execution_token;
        $action = $this->app->make(FinalizeGenerationFailure::class);

        $action->handle((int) $generation->generation_id, $token, GenerationErrorCode::IncompleteOutput);
        $action->handle((int) $generation->generation_id, $token, GenerationErrorCode::IncompleteOutput);

        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->fresh()->usageLog->status);
        $this->assertNotNull($generation->fresh()->failed_at);
        $this->assertNull($generation->fresh()->completed_at);
    }

    private function processingGeneration(int $questionCount): AiGeneration
    {
        $generation = $this->startGeneration(User::factory()->create(), questionCount: $questionCount);
        $generation->generation_status = GenerationStatus::PROCESSING;
        $generation->execution_token = (string) Str::uuid();
        $generation->started_at = now();
        $generation->save();

        return $generation->fresh();
    }
}
