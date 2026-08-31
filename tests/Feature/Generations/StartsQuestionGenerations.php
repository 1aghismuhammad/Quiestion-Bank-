<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\StartQuestionGeneration;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\OutputLanguage;
use App\Enums\PlanCode;
use App\Enums\QuestionType;
use App\Enums\SubscriptionStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

trait StartsQuestionGenerations
{
    protected function startGeneration(
        User $user,
        ?Material $material = null,
        AssessmentType $assessmentType = AssessmentType::FORMATIVE,
        DifficultyLevel $difficultyLevel = DifficultyLevel::MEDIUM,
        QuestionType $questionType = QuestionType::MULTIPLE_CHOICE,
        int $questionCount = 5,
        OutputLanguage $outputLanguage = OutputLanguage::ID,
    ): AiGeneration {
        Queue::fake([GenerateQuestionsJob::class]);

        $material ??= Material::factory()->text()->for($user)->create();

        return $this->starter()->handle(
            $user,
            $material,
            $assessmentType,
            $difficultyLevel,
            $questionType,
            $questionCount,
            $outputLanguage,
        );
    }

    protected function starter(): StartQuestionGeneration
    {
        return $this->app->make(StartQuestionGeneration::class);
    }

    protected function freePlan(): Plan
    {
        return Plan::query()->where('code', PlanCode::FREE)->firstOrFail();
    }

    protected function proPlan(): Plan
    {
        return Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
    }

    protected function proWindow(User $user, Carbon $startsAt, Carbon $endsAt): Subscription
    {
        return Subscription::factory()->for($user)->for($this->proPlan())->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => SubscriptionStatus::ACTIVE,
        ]);
    }
}
