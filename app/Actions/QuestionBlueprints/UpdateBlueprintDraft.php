<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\AssessmentType;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintRowOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateBlueprintDraft
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(
        private AssertReadyMatchingProfile $assertProfile,
        private AssertSimpleBlueprintShape $assertShape,
        private PersistBlueprintRows $persistRows,
        private ResolveBlueprintRowContexts $resolveContexts,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function handle(
        User $actor,
        QuestionBlueprint $blueprint,
        string $title,
        AssessmentType $assessmentType,
        array $rows,
    ): QuestionBlueprint {
        $title = trim($title);
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);

        if ($title === '' || mb_strlen($title, 'UTF-8') > $maxTitle) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return DB::transaction(function () use ($actor, $blueprint, $title, $assessmentType, $rows): QuestionBlueprint {
            $material = $this->lockUserAndMaterial((int) $actor->id, (int) $blueprint->material_id);
            $graph = $this->lockBlueprintGraph($blueprint);
            $locked = $graph['blueprint'];

            if ((int) $locked->user_id !== (int) $actor->id) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }

            if ($locked->lifecycle_status === BlueprintLifecycleStatus::Confirmed) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ConfirmedImmutable);
            }

            $profile = $this->assertProfile->requireReferencedReady($material, (int) $locked->profile_version_id);
            $this->assertShape->handle($rows);

            $locked->title = $title;
            $locked->assessment_type = $assessmentType;
            $locked->save();

            $this->persistRows->replace($locked, $this->resolveContexts->attach($material, $profile, $rows), BlueprintRowOrigin::Manual);

            return $locked->refresh()->load(['rows.contexts', 'series']);
        });
    }
}
