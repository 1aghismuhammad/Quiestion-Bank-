<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\GenerationRunErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\OutputLanguage;
use App\Enums\UsageStatus;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Models\AiGenerationRun;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RetryFailedGenerationRun
{
    use LocksGenerationRun;

    public function __construct(private StartGenerationRun $start) {}

    public function handle(User $actor, AiGenerationRun $run, string $idempotencyKey): AiGenerationRun
    {
        $blueprintId = DB::transaction(function () use ($actor, $run): int {
            $locked = $this->lockUserAndRun((int) $run->generation_run_id);
            $this->lockRunChildrenAscending((int) $locked->generation_run_id);
            $usage = $this->lockRunUsage($locked);

            if ((int) $locked->user_id !== (int) $actor->id) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::MaterialIneligible);
            }

            if ($locked->status !== GenerationRunStatus::Failed || $usage->status !== UsageStatus::RELEASED) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::RunNotFailed);
            }

            return (int) $locked->blueprint_id;
        });

        $blueprint = QuestionBlueprint::query()->findOrFail($blueprintId);

        return $this->start->handle(
            $actor,
            $blueprint,
            $run->output_language instanceof OutputLanguage
                ? $run->output_language
                : OutputLanguage::from((string) $run->getAttributes()['output_language']),
            $idempotencyKey,
            (int) $run->generation_run_id,
        );
    }
}
