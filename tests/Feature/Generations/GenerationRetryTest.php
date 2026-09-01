<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\ConsumeGenerationCredit;
use App\Actions\Generations\FinalizeGenerationFailure;
use App\Actions\Generations\RetryFailedQuestionGeneration;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\MaterialStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GenerationRetryTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_failed_generation_retry_creates_new_reserved_child(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $failed = $this->startGeneration(
            $owner,
            $material,
            AssessmentType::SUMMATIVE,
            DifficultyLevel::HOTS,
            QuestionType::MULTIPLE_CHOICE,
            7,
            OutputLanguage::EN,
        );
        $this->failGeneration($failed);
        $failedToken = $failed->fresh()->execution_token;
        $oldError = $failed->fresh()->error_code;

        Queue::fake([GenerateQuestionsJob::class]);

        $this->actingAs($owner)
            ->post(route('generations.retry', $failed))
            ->assertRedirect();

        $child = AiGeneration::query()
            ->where('parent_generation_id', $failed->generation_id)
            ->firstOrFail();

        $this->assertSame($owner->id, $child->user_id);
        $this->assertSame($material->material_id, $child->material_id);
        $this->assertSame(GenerationStatus::QUEUED, $child->generation_status);
        $this->assertSame(AssessmentType::SUMMATIVE, $child->assessment_type);
        $this->assertSame(DifficultyLevel::HOTS, $child->difficulty_level);
        $this->assertSame(QuestionType::MULTIPLE_CHOICE, $child->question_type);
        $this->assertSame(7, $child->question_count);
        $this->assertSame(OutputLanguage::EN, $child->output_language);
        $this->assertSame((int) $failed->generation_id, (int) $child->parent_generation_id);
        $this->assertSame(UsageStatus::RESERVED, $child->usageLog->status);

        $failed->refresh();
        $this->assertSame(GenerationStatus::FAILED, $failed->generation_status);
        $this->assertSame($oldError, $failed->error_code);
        $this->assertSame($failedToken, $failed->execution_token);
        $this->assertSame(UsageStatus::RELEASED, $failed->usageLog->fresh()->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_id', $failed->generation_id)->count());

        Queue::assertPushed(GenerateQuestionsJob::class, 1);
        Queue::assertPushed(GenerateQuestionsJob::class, function (GenerateQuestionsJob $job) use ($child): bool {
            return $job->generationId === (int) $child->generation_id;
        });
    }

    public function test_non_failed_generations_cannot_be_retried(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $queued = $this->startGeneration($owner, $material);
        $processing = $this->startGeneration($owner, $material);
        $processing->forceFill([
            'generation_status' => GenerationStatus::PROCESSING,
            'execution_token' => 'tok',
        ])->save();

        $this->actingAs($owner)
            ->from(route('generations.show', $queued))
            ->post(route('generations.retry', $queued))
            ->assertRedirect(route('generations.show', $queued))
            ->assertSessionHasErrors('generation');

        $this->actingAs($owner)
            ->from(route('generations.show', $processing))
            ->post(route('generations.retry', $processing))
            ->assertSessionHasErrors('generation');

        $completed = AiGeneration::factory()->for($owner)->create([
            'material_id' => $material->material_id,
            'generation_status' => GenerationStatus::COMPLETED,
        ]);
        $cancelled = AiGeneration::factory()->for($owner)->create([
            'material_id' => $material->material_id,
            'generation_status' => GenerationStatus::CANCELLED,
        ]);

        $this->actingAs($owner)
            ->post(route('generations.retry', $completed))
            ->assertSessionHasErrors('generation');

        $this->actingAs($owner)
            ->post(route('generations.retry', $cancelled))
            ->assertSessionHasErrors('generation');

        $this->assertSame(0, AiGeneration::query()->whereNotNull('parent_generation_id')->count());
    }

    public function test_stranger_retry_is_not_found(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $failed = $this->startGeneration($owner);
        $this->failGeneration($failed);

        $this->actingAs($stranger)
            ->post(route('generations.retry', $failed))
            ->assertNotFound();

        $this->assertSame(1, AiGeneration::query()->count());
    }

    public function test_retry_rechecks_material_eligibility(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $failed = $this->startGeneration($owner, $material);
        $this->failGeneration($failed);
        $material->update(['status' => MaterialStatus::ARCHIVED]);

        $this->actingAs($owner)
            ->from(route('generations.show', $failed))
            ->post(route('generations.retry', $failed))
            ->assertRedirect(route('generations.show', $failed))
            ->assertSessionHasErrors('material');

        $this->assertSame(1, AiGeneration::query()->count());
    }

    public function test_retry_rechecks_quota(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $first = $this->startGeneration($owner, $material);
        $this->app->make(ConsumeGenerationCredit::class)->handle($first);
        $first->forceFill(['generation_status' => GenerationStatus::COMPLETED])->save();

        $second = $this->startGeneration($owner, $material);
        $this->app->make(ConsumeGenerationCredit::class)->handle($second);
        $second->forceFill(['generation_status' => GenerationStatus::COMPLETED])->save();

        $failed = AiGeneration::factory()->for($owner)->create([
            'material_id' => $material->material_id,
            'generation_status' => GenerationStatus::FAILED,
            'assessment_type' => AssessmentType::FORMATIVE,
            'difficulty_level' => DifficultyLevel::MEDIUM,
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'question_count' => 5,
            'output_language' => OutputLanguage::ID,
        ]);
        AiUsageLog::factory()->for($failed, 'generation')->released()->create([
            'user_id' => $owner->id,
            'plan_id' => $this->freePlan()->plan_id,
            'subscription_id' => null,
        ]);

        $this->actingAs($owner)
            ->from(route('generations.show', $failed))
            ->post(route('generations.retry', $failed))
            ->assertSessionHasErrors('quota');

        $this->assertSame(3, AiGeneration::query()->count());
    }

    public function test_action_rejects_non_failed_without_http(): void
    {
        $owner = $this->createCompleteUser();
        $queued = $this->startGeneration($owner);

        $this->expectException(ValidationException::class);
        $this->app->make(RetryFailedQuestionGeneration::class)->handle($owner, $queued);
    }

    public function test_start_rejects_foreign_parent_without_creating_child(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $foreign = $this->startGeneration($stranger);
        $this->failGeneration($foreign);

        $this->assertParentLineageRejected($owner, $material, (int) $foreign->generation_id);
        $this->assertSame(GenerationStatus::FAILED, $foreign->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $foreign->usageLog->fresh()->status);
    }

    public function test_start_rejects_completed_parent_without_creating_child(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $completed = $this->startGeneration($owner, $material);
        $this->app->make(ConsumeGenerationCredit::class)->handle($completed);
        $completed->forceFill([
            'generation_status' => GenerationStatus::COMPLETED,
            'completed_at' => now(),
        ])->save();

        $this->assertParentLineageRejected($owner, $material, (int) $completed->generation_id);
        $this->assertSame(GenerationStatus::COMPLETED, $completed->fresh()->generation_status);
        $this->assertSame(UsageStatus::CHARGED, $completed->usageLog->fresh()->status);
    }

    public function test_failed_reserved_parent_cannot_be_retried(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $failed = $this->markFailedLeavingUsage($this->startGeneration($owner, $material), UsageStatus::RESERVED);

        $this->actingAs($owner)
            ->from(route('generations.show', $failed))
            ->post(route('generations.retry', $failed))
            ->assertRedirect(route('generations.show', $failed))
            ->assertSessionHasErrors('generation');

        $this->assertParentLineageRejected($owner, $material, (int) $failed->generation_id);
        $this->assertSame(GenerationStatus::FAILED, $failed->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $failed->usageLog->fresh()->status);
    }

    public function test_failed_charged_parent_cannot_be_retried(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $failed = $this->startGeneration($owner, $material);
        $this->app->make(ConsumeGenerationCredit::class)->handle($failed);
        $failed = $this->markFailedLeavingUsage($failed, UsageStatus::CHARGED);

        $this->actingAs($owner)
            ->from(route('generations.show', $failed))
            ->post(route('generations.retry', $failed))
            ->assertRedirect(route('generations.show', $failed))
            ->assertSessionHasErrors('generation');

        $this->assertParentLineageRejected($owner, $material, (int) $failed->generation_id);
        $this->assertSame(GenerationStatus::FAILED, $failed->fresh()->generation_status);
        $this->assertSame(UsageStatus::CHARGED, $failed->usageLog->fresh()->status);
    }

    public function test_failed_released_parent_creates_child_and_leaves_parent_unchanged(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $failed = $this->startGeneration($owner, $material);
        $this->failGeneration($failed);
        $parentSnapshot = $failed->fresh()->only([
            'generation_status',
            'error_code',
            'error_message',
            'execution_token',
            'attempt_number',
            'question_count',
        ]);
        $parentUsageStatus = $failed->usageLog->fresh()->status;
        $parentUpdatedAt = $failed->fresh()->updated_at?->toJSON();

        Queue::fake([GenerateQuestionsJob::class]);

        $child = $this->starter()->handle(
            $owner,
            $material,
            $failed->assessment_type,
            $failed->difficulty_level,
            $failed->question_type,
            (int) $failed->question_count,
            $failed->output_language,
            (int) $failed->generation_id,
        );

        $this->assertSame($owner->id, $child->user_id);
        $this->assertSame($material->material_id, $child->material_id);
        $this->assertSame(GenerationStatus::QUEUED, $child->generation_status);
        $this->assertSame((int) $failed->generation_id, (int) $child->parent_generation_id);
        $this->assertSame(UsageStatus::RESERVED, $child->usageLog->status);

        $failed->refresh();
        $this->assertSame($parentSnapshot, $failed->only(array_keys($parentSnapshot)));
        $this->assertSame($parentUsageStatus, $failed->usageLog->fresh()->status);
        $this->assertSame($parentUpdatedAt, $failed->updated_at?->toJSON());
        $this->assertSame(1, AiUsageLog::query()->where('generation_id', $failed->generation_id)->count());

        Queue::assertPushed(GenerateQuestionsJob::class, 1);
        Queue::assertPushed(GenerateQuestionsJob::class, function (GenerateQuestionsJob $job) use ($child): bool {
            return $job->generationId === (int) $child->generation_id;
        });
    }

    public function test_failed_parent_without_usage_cannot_be_retried(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $failed = AiGeneration::factory()->for($owner)->create([
            'material_id' => $material->material_id,
            'generation_status' => GenerationStatus::FAILED,
            'failed_at' => now(),
        ]);

        $this->assertNull($failed->usageLog);
        $this->assertParentLineageRejected($owner, $material, (int) $failed->generation_id);
    }

    private function assertParentLineageRejected(User $actor, Material $material, int $parentId): void
    {
        Queue::fake([GenerateQuestionsJob::class]);
        $generationCount = AiGeneration::query()->count();
        $usageCount = AiUsageLog::query()->count();

        try {
            $this->starter()->handle(
                $actor,
                $material,
                AssessmentType::FORMATIVE,
                DifficultyLevel::MEDIUM,
                QuestionType::MULTIPLE_CHOICE,
                5,
                OutputLanguage::ID,
                $parentId,
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generation', $exception->errors());
        }

        $this->assertSame($generationCount, AiGeneration::query()->count());
        $this->assertSame($usageCount, AiUsageLog::query()->count());
        $this->assertSame(0, AiGeneration::query()->where('parent_generation_id', $parentId)->count());
        Queue::assertNothingPushed();
    }

    private function markFailedLeavingUsage(AiGeneration $generation, UsageStatus $expectedUsage): AiGeneration
    {
        $generation->forceFill([
            'generation_status' => GenerationStatus::FAILED,
            'failed_at' => now(),
            'error_code' => GenerationErrorCode::IncompleteOutput->value,
            'error_message' => GenerationErrorCode::IncompleteOutput->userMessage(),
        ])->save();

        $this->assertSame($expectedUsage, $generation->usageLog->fresh()->status);

        return $generation->fresh();
    }

    private function failGeneration(AiGeneration $generation): void
    {
        $this->app->make(FinalizeGenerationFailure::class)->handle(
            (int) $generation->generation_id,
            (string) $generation->execution_token,
            GenerationErrorCode::IncompleteOutput,
        );
    }
}
