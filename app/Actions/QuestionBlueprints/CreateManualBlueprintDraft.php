<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Actions\MaterialProfiles\AssertMaterialEligibleForProfileAnalysis;
use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintRowOrigin;
use App\Enums\BlueprintSource;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateManualBlueprintDraft
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
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
        Material $material,
        string $title,
        AssessmentType $assessmentType,
        array $rows,
    ): QuestionBlueprint {
        $title = trim($title);
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);

        if ($title === '' || mb_strlen($title, 'UTF-8') > $maxTitle) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return DB::transaction(function () use ($actor, $material, $title, $assessmentType, $rows): QuestionBlueprint {
            $locked = $this->lockUserAndMaterial((int) $actor->id, (int) $material->material_id);
            try {
                $this->assertEligible->handle($locked);
            } catch (MaterialProfileRejectedException) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }
            $profile = $this->assertProfile->requireMatchingReady($locked);
            $this->lockProfileVersion((int) $profile->profile_version_id);
            $this->assertShape->handle($rows);

            $fingerprint = $this->assertProfile->fingerprint($locked);

            $series = QuestionBlueprintSeries::query()->create([
                'user_id' => $locked->user_id,
                'material_id' => $locked->material_id,
            ]);

            $blueprint = QuestionBlueprint::query()->create([
                'blueprint_series_id' => $series->blueprint_series_id,
                'user_id' => $locked->user_id,
                'material_id' => $locked->material_id,
                'profile_version_id' => $profile->profile_version_id,
                'version' => 1,
                'lifecycle_status' => BlueprintLifecycleStatus::Draft,
                'source' => BlueprintSource::Manual,
                'ai_fill_status' => BlueprintAiFillStatus::None,
                'assessment_type' => $assessmentType,
                'title' => $title,
                'material_content_hash' => $fingerprint['material_content_hash'],
                'material_file_hash' => $fingerprint['material_file_hash'],
                'extractor_implementation' => $fingerprint['extractor_implementation'],
            ]);

            $this->persistRows->replace(
                $blueprint,
                $this->resolveContexts->attach($locked, $profile, $rows),
                BlueprintRowOrigin::Manual,
            );

            return $blueprint->refresh()->load(['rows.contexts', 'series']);
        });
    }
}
