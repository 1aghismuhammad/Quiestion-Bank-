<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\AssertMaterialEligibleForGeneration;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\ExtractionStatus;
use App\Enums\GenerationStatus;
use App\Enums\MaterialStatus;
use App\Enums\QuestionType;
use App\Enums\RoleName;
use App\Enums\SourceType;
use App\Enums\UsageStatus;
use App\Exceptions\Subscriptions\AmbiguousEntitlementException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Exceptions\Subscriptions\InvalidGenerationQuotaException;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StartQuestionGenerationTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-10-15 12:00:00');
        Carbon::setTestNow($this->now);
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_start_creates_queued_generation_and_reserved_usage(): void
    {
        $user = User::factory()->create();

        $generation = $this->startGeneration(
            $user,
            questionCount: 7,
            assessmentType: AssessmentType::SUMMATIVE,
            difficultyLevel: DifficultyLevel::HOTS,
            questionType: QuestionType::ESSAY,
        );

        $this->assertSame(GenerationStatus::QUEUED, $generation->generation_status);
        $this->assertSame(7, $generation->question_count);
        $this->assertSame(AssessmentType::SUMMATIVE, $generation->assessment_type);
        $this->assertSame(DifficultyLevel::HOTS, $generation->difficulty_level);
        $this->assertSame(QuestionType::ESSAY, $generation->question_type);
        $this->assertSame(1, $generation->attempt_number);
        $this->assertNull($generation->parent_generation_id);
        $this->assertTrue($generation->queued_at?->equalTo($this->now));
        $this->assertNull($generation->started_at);
        $this->assertNull($generation->completed_at);

        $usage = $generation->usageLog;
        $this->assertNotNull($usage);
        $this->assertSame(UsageStatus::RESERVED, $usage->status);
        $this->assertSame($user->id, $usage->user_id);
        $this->assertSame($this->freePlan()->plan_id, $usage->plan_id);
        $this->assertNull($usage->subscription_id);
        $this->assertNull($usage->window_start);
        $this->assertNull($usage->window_end);
        $this->assertTrue($usage->reserved_at->equalTo($this->now));
        $this->assertNull($usage->finalized_at);
        $this->assertSame(1, AiUsageLog::query()->count());
    }

    public function test_question_count_one_is_allowed_and_has_no_product_maximum(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());

        $generation = $this->startGeneration($user, questionCount: 500);

        $this->assertSame(500, $generation->question_count);
        $this->assertSame(1, $this->startGeneration($user, questionCount: 1)->question_count);
    }

    public function test_question_count_below_one_is_rejected_without_rows(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();

        try {
            $this->starter()->handle(
                $user,
                $material,
                AssessmentType::FORMATIVE,
                DifficultyLevel::MEDIUM,
                QuestionType::MULTIPLE_CHOICE,
                0,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('question_count', $exception->errors());
        }

        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_two_starts_create_two_reservations_when_capacity_allows(): void
    {
        $user = User::factory()->create();

        $first = $this->startGeneration($user);
        $second = $this->startGeneration($user);

        $this->assertNotSame($first->generation_id, $second->generation_id);
        $this->assertSame(2, AiGeneration::query()->count());
        $this->assertSame(2, AiUsageLog::query()->count());
    }

    public function test_third_free_start_is_rejected_when_two_reservations_occupy_capacity(): void
    {
        $user = User::factory()->create();
        $this->startGeneration($user);
        $this->startGeneration($user);

        try {
            $this->startGeneration($user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quota', $exception->errors());
        }

        $this->assertSame(2, AiGeneration::query()->count());
        $this->assertSame(2, AiUsageLog::query()->count());
    }

    public function test_cross_user_start_is_authorization_denial(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->text()->for($owner)->create();

        $this->expectException(AuthorizationException::class);

        $this->starter()->handle(
            $stranger,
            $material,
            AssessmentType::FORMATIVE,
            DifficultyLevel::MEDIUM,
            QuestionType::MULTIPLE_CHOICE,
            5,
        );
    }

    public function test_admin_cannot_start_generation_on_another_users_material(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN->value)->firstOrFail());
        $material = Material::factory()->text()->for($owner)->create();

        try {
            $this->starter()->handle(
                $admin,
                $material,
                AssessmentType::FORMATIVE,
                DifficultyLevel::MEDIUM,
                QuestionType::MULTIPLE_CHOICE,
                5,
            );
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            $this->assertSame(0, AiGeneration::query()->count());
        }
    }

    public function test_owned_soft_deleted_material_is_validation_not_authorization(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $material->delete();
        $trashed = Material::withTrashed()->findOrFail($material->material_id);

        $this->assertTrue($user->can('generate', $trashed));

        try {
            $this->startGeneration($user, $trashed);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('material', $exception->errors());
        }

        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_cross_user_soft_deleted_material_is_authorization_denial(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->text()->for($owner)->create();
        $material->delete();
        $trashed = Material::withTrashed()->findOrFail($material->material_id);

        try {
            $this->starter()->handle(
                $stranger,
                $trashed,
                AssessmentType::FORMATIVE,
                DifficultyLevel::MEDIUM,
                QuestionType::MULTIPLE_CHOICE,
                5,
            );
            $this->fail('Expected AuthorizationException');
        } catch (AuthorizationException) {
            $this->assertSame(0, AiGeneration::query()->count());
            $this->assertSame(0, AiUsageLog::query()->count());
        }
    }

    public function test_owned_draft_material_is_validation_not_authorization(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'status' => MaterialStatus::DRAFT,
        ]);

        $this->assertTrue($user->can('generate', $material));

        try {
            $this->startGeneration($user, $material);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('material', $exception->errors());
        }

        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_owned_archived_material_is_validation(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->archived()->create();

        $this->assertTrue($user->can('generate', $material));
        $this->expectException(ValidationException::class);
        $this->startGeneration($user, $material);
    }

    public function test_upload_pending_processing_and_failed_are_ineligible(): void
    {
        $user = User::factory()->create();

        foreach ([ExtractionStatus::PENDING, ExtractionStatus::PROCESSING, ExtractionStatus::FAILED] as $extraction) {
            $material = Material::factory()->upload()->for($user)->create([
                'status' => MaterialStatus::READY,
                'extraction_status' => $extraction,
            ]);

            try {
                $this->startGeneration($user, $material);
                $this->fail('Expected ValidationException for '.$extraction->value);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_ready_upload_with_completed_extraction_is_eligible(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->upload()->for($user)->create([
            'status' => MaterialStatus::READY,
            'extraction_status' => ExtractionStatus::COMPLETED,
            'content' => 'extracted',
        ]);

        $generation = $this->startGeneration($user, $material);

        $this->assertSame($material->material_id, $generation->material_id);
    }

    public function test_start_uses_locked_database_material_not_stale_in_memory_copy(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $stale = Material::query()->findOrFail($material->material_id);
        $this->assertSame(MaterialStatus::READY, $stale->status);

        Material::query()->whereKey($material->material_id)->update([
            'status' => MaterialStatus::ARCHIVED->value,
        ]);

        $this->expectException(ValidationException::class);
        $this->startGeneration($user, $stale);
    }

    public function test_stale_ineligible_copy_succeeds_when_database_row_became_ready(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->upload()->for($user)->create([
            'status' => MaterialStatus::DRAFT,
            'extraction_status' => ExtractionStatus::PENDING,
        ]);
        $stale = Material::query()->findOrFail($material->material_id);

        Material::query()->whereKey($material->material_id)->update([
            'status' => MaterialStatus::READY->value,
            'extraction_status' => ExtractionStatus::COMPLETED->value,
            'content' => 'extracted',
        ]);

        $generation = $this->startGeneration($user, $stale);

        $this->assertSame($material->material_id, $generation->material_id);
        $this->assertSame(SourceType::UPLOAD, $generation->material->source_type);
    }

    public function test_eligibility_action_does_not_encode_ownership(): void
    {
        $owner = User::factory()->create();
        $strangerMaterial = Material::factory()->text()->for(User::factory())->create();

        $this->app->make(AssertMaterialEligibleForGeneration::class)->handle($strangerMaterial);

        $this->assertTrue($owner->cannot('generate', $strangerMaterial));
    }

    public function test_ambiguous_entitlement_fails_closed_without_rows(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDays(10), $this->now->copy()->addDays(10));
        $this->proWindow($user, $this->now->copy()->subDays(2), $this->now->copy()->addDays(20));

        try {
            $this->startGeneration($user);
            $this->fail('Expected AmbiguousEntitlementException');
        } catch (AmbiguousEntitlementException) {
            $this->assertSame(0, AiGeneration::query()->count());
            $this->assertSame(0, AiUsageLog::query()->count());
        }
    }

    public function test_invalid_entitlement_queue_fails_closed_without_rows(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->addMonth(), $this->now->copy()->addDay());

        try {
            $this->startGeneration($user);
            $this->fail('Expected InvalidEntitlementException');
        } catch (InvalidEntitlementException) {
            $this->assertSame(0, AiGeneration::query()->count());
            $this->assertSame(0, AiUsageLog::query()->count());
        }
    }

    public function test_corrupt_free_quota_config_fails_closed(): void
    {
        $this->freePlan()->update(['generation_limit' => 0]);
        $user = User::factory()->create();

        $this->expectException(InvalidGenerationQuotaException::class);
        $this->startGeneration($user);
    }
}
