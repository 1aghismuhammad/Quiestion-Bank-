<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Actions\Subscriptions\ResolveActivePro;
use App\Enums\AssessmentType;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
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
        private AssertBlueprintShape $assertShape,
        private PersistBlueprintRows $persistRows,
        private ResolveBlueprintRowContexts $resolveContexts,
        private ResolveActivePro $resolveActivePro,
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
        ?BlueprintMode $mode = null,
    ): QuestionBlueprint {
        $title = trim($title);
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);

        if ($title === '' || mb_strlen($title, 'UTF-8') > $maxTitle) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return DB::transaction(function () use ($actor, $blueprint, $title, $assessmentType, $rows, $mode): QuestionBlueprint {
            $material = $this->lockUserAndMaterial((int) $actor->id, (int) $blueprint->material_id);
            $graph = $this->lockBlueprintGraph($blueprint);
            $locked = $graph['blueprint'];

            if ((int) $locked->user_id !== (int) $actor->id) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }

            if ($locked->lifecycle_status === BlueprintLifecycleStatus::Confirmed) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ConfirmedImmutable);
            }

            if ($locked->ai_fill_status->isInFlight()) {
                throw new BlueprintRejectedException(BlueprintErrorCode::InFlightExists);
            }

            $persisted = $locked->mode instanceof BlueprintMode ? $locked->mode : BlueprintMode::Simple;
            $destination = $mode ?? $persisted;

            if (
                ($persisted === BlueprintMode::Advanced || $destination === BlueprintMode::Advanced)
                && ! $this->resolveActivePro->handle($actor)
            ) {
                throw new BlueprintRejectedException(BlueprintErrorCode::AdvancedRequiresPro);
            }

            $profile = $this->assertProfile->requireReferencedReady($material, (int) $locked->profile_version_id);
            $this->assertShape->handle($rows, $destination);

            $locked->title = $title;
            $locked->assessment_type = $assessmentType;
            $locked->mode = $destination;
            $locked->save();

            $this->persistRows->replace($locked, $this->resolveContexts->attach($material, $profile, $rows), BlueprintRowOrigin::Manual);

            return $locked->refresh()->load(['rows.contexts', 'series']);
        });
    }
}
