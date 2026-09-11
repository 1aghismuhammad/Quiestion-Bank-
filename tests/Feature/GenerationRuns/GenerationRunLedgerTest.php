<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\ResolveGenerationUsage;
use App\Actions\Generations\StartQuestionGeneration;
use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationRunErrorCode;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\User;
use App\Support\Generations\UsageSubjectXor;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class GenerationRunLedgerTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake([GenerateQuestionsJob::class]);
    }

    public function test_legacy_start_writes_one_credit_xor_generation_id(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();

        $generation = $this->app->make(StartQuestionGeneration::class)->handle(
            $user,
            $material,
            AssessmentType::FORMATIVE,
            DifficultyLevel::MEDIUM,
            QuestionType::MULTIPLE_CHOICE,
            5,
            OutputLanguage::ID,
        );

        $usage = $generation->usageLog;
        $this->assertNotNull($usage);
        $this->assertSame(1, (int) $usage->credits);
        $this->assertNull($usage->generation_run_id);
        $this->assertSame((int) $generation->generation_id, (int) $usage->generation_id);
        UsageSubjectXor::assert((int) $usage->generation_id, $usage->generation_run_id);
    }

    public function test_sum_counts_multi_credit_reservations(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Paragraf materi untuk generasi. ', 20),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(5)]));

        $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $snapshot = $this->app->make(ResolveGenerationUsage::class)->handle(
            $user,
            $this->app->make(ResolveGenerationQuota::class)->handle(
                $user,
                $this->app->make(ResolveUserEntitlement::class)->handle($user),
            ),
        );

        $this->assertSame(0, $snapshot->consumed);
        $this->assertSame(1, $snapshot->reserved);
        $this->assertSame(1, $snapshot->available);

        $usage = AiUsageLog::query()->whereNotNull('generation_run_id')->first();
        $this->assertNotNull($usage);
        $this->assertNull($usage->generation_id);
        $this->assertSame(1, (int) $usage->credits);
        $this->assertSame(UsageStatus::RESERVED, $usage->status);
        UsageSubjectXor::assert($usage->generation_id, (int) $usage->generation_run_id);
    }

    public function test_start_without_ready_profile_writes_zero_usage(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));
        $material->update(['content' => $material->content.' berubah']);

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $blueprint->fresh(),
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $this->fail('Stale blueprint must be rejected.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertContains($exception->errorCode, [
                GenerationRunErrorCode::BlueprintStale,
                GenerationRunErrorCode::ProfileStale,
                GenerationRunErrorCode::ProfileRequired,
            ]);
        }

        $this->assertSame(0, AiUsageLog::query()->whereNotNull('generation_run_id')->count());
    }

    public function test_idempotent_start_does_not_double_reserve(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));
        $key = (string) Str::uuid();
        $start = $this->app->make(StartGenerationRun::class);

        $first = $start->handle($user, $blueprint, OutputLanguage::ID, $key);
        $second = $start->handle($user, $blueprint, OutputLanguage::ID, $key);

        $this->assertSame($first->generation_run_id, $second->generation_run_id);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $first->generation_run_id)->count());
        Queue::assertPushed(GenerateQuestionsJob::class, 1);
    }

    public function test_conflicting_fingerprint_rejects_without_writes(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));
        $key = (string) Str::uuid();
        $start = $this->app->make(StartGenerationRun::class);
        $start->handle($user, $blueprint, OutputLanguage::ID, $key);

        try {
            $start->handle($user, $blueprint, OutputLanguage::EN, $key);
            $this->fail('Conflicting fingerprint must be rejected.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::IdempotencyConflict, $exception->errorCode);
        }

        $this->assertSame(1, AiUsageLog::query()->count());
    }

    public function test_draft_and_oversize_starts_write_zero_usage(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $draft = $this->createDraft($user, $material);
        $start = $this->app->make(StartGenerationRun::class);

        try {
            $start->handle($user, $draft, OutputLanguage::ID, (string) Str::uuid());
            $this->fail('Draft blueprint must be rejected.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::BlueprintNotConfirmed, $exception->errorCode);
        }

        $confirmed = $this->confirmDraft($user, $draft);
        $confirmed->rows()->first()?->update(['requested_count' => 11]);

        try {
            $start->handle($user, $confirmed->fresh(['rows']), OutputLanguage::ID, (string) Str::uuid());
            $this->fail('Total above 10 must be rejected.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_insufficient_quota_inserts_nothing(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));
        $start = $this->app->make(StartGenerationRun::class);

        $start->handle($user, $blueprint, OutputLanguage::ID, (string) Str::uuid());
        $start->handle($user, $blueprint, OutputLanguage::ID, (string) Str::uuid());

        try {
            $start->handle($user, $blueprint, OutputLanguage::ID, (string) Str::uuid());
            $this->fail('Third start must exceed the free quota.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::QuotaInsufficient, $exception->errorCode);
        }

        $this->assertSame(2, AiUsageLog::query()->whereNotNull('generation_run_id')->count());
        $this->assertSame(2, AiGenerationRun::query()->count());
    }

    public function test_children_have_no_usage_rows(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            $this->sampleRow(2),
            array_merge($this->sampleRow(2), ['topic' => 'Penerapan']),
        ]));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $childIds = AiGeneration::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->pluck('generation_id');

        $this->assertSame(2, $childIds->count());
        $this->assertSame(0, AiUsageLog::query()->whereIn('generation_id', $childIds)->count());
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }
}
