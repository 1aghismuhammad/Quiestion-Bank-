<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StartQuestionGeneration
{
    public function __construct(
        private ResolveUserEntitlement $resolveEntitlement,
        private ResolveGenerationQuota $resolveQuota,
        private ResolveGenerationUsage $resolveUsage,
        private AssertMaterialEligibleForGeneration $assertEligible,
    ) {}

    public function handle(
        User $actor,
        Material $material,
        AssessmentType $assessmentType,
        DifficultyLevel $difficultyLevel,
        QuestionType $questionType,
        int $questionCount,
        OutputLanguage $outputLanguage,
    ): AiGeneration {
        if ($questionType !== QuestionType::MULTIPLE_CHOICE) {
            throw ValidationException::withMessages([
                'question_type' => 'Tipe soal ini belum didukung.',
            ]);
        }

        if ($questionCount < 1) {
            throw ValidationException::withMessages([
                'question_count' => 'Jumlah soal minimal 1.',
            ]);
        }

        $maxQuestions = (int) config('generation.max_questions', 10);

        if ($questionCount > $maxQuestions) {
            throw ValidationException::withMessages([
                'question_count' => "Jumlah soal maksimal {$maxQuestions}.",
            ]);
        }

        $materialKey = $material->getKey();

        $generation = DB::transaction(function () use (
            $actor,
            $materialKey,
            $assessmentType,
            $difficultyLevel,
            $questionType,
            $questionCount,
            $outputLanguage,
        ): AiGeneration {
            $owner = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

            $lockedMaterial = Material::withTrashed()
                ->whereKey($materialKey)
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($owner)->authorize('generate', $lockedMaterial);

            $this->assertEligible->handle($lockedMaterial);

            $entitlement = $this->resolveEntitlement->handle($owner);
            $quota = $this->resolveQuota->handle($owner, $entitlement);
            $usage = $this->resolveUsage->handle($owner, $quota);

            if ($usage->available < 1) {
                throw ValidationException::withMessages([
                    'quota' => 'Kuota generasi paket Anda tidak mencukupi.',
                ]);
            }

            $generation = AiGeneration::query()->create([
                'user_id' => $owner->id,
                'material_id' => $lockedMaterial->material_id,
                'assessment_type' => $assessmentType,
                'difficulty_level' => $difficultyLevel,
                'question_type' => $questionType,
                'question_count' => $questionCount,
                'output_language' => $outputLanguage,
                'generation_status' => GenerationStatus::QUEUED,
                'execution_token' => null,
                'error_message' => null,
                'error_code' => null,
                'attempt_number' => 0,
                'parent_generation_id' => null,
                'queued_at' => now(),
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
            ]);

            AiUsageLog::query()->create([
                'user_id' => $owner->id,
                'plan_id' => $quota->entitlement->plan->plan_id,
                'subscription_id' => $quota->entitlement->subscription?->subscription_id,
                'generation_id' => $generation->generation_id,
                'status' => UsageStatus::RESERVED,
                'window_start' => $quota->windowStart,
                'window_end' => $quota->windowEnd,
                'reserved_at' => now(),
                'finalized_at' => null,
            ]);

            return $generation->load('usageLog');
        });

        GenerateQuestionsJob::dispatch((int) $generation->generation_id);

        return $generation;
    }
}
