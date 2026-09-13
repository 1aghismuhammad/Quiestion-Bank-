<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Actions\MaterialProfiles\AssertMaterialEligibleForProfileAnalysis;
use App\Actions\Subscriptions\ResolveActivePro;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConfirmQuestionBlueprint
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
        private AssertReadyMatchingProfile $assertProfile,
        private AssertBlueprintShape $assertShape,
        private AssertBlueprintRowContexts $assertContexts,
        private ResolveActivePro $resolveActivePro,
    ) {}

    public function handle(User $actor, QuestionBlueprint $blueprint): QuestionBlueprint
    {
        return DB::transaction(function () use ($actor, $blueprint): QuestionBlueprint {
            $material = $this->lockUserAndMaterial((int) $actor->id, (int) $blueprint->material_id);
            $graph = $this->lockBlueprintGraph($blueprint);
            $locked = $graph['blueprint'];

            if ((int) $locked->user_id !== (int) $actor->id) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }

            if ($locked->lifecycle_status === BlueprintLifecycleStatus::Confirmed) {
                return $locked->refresh()->load(['rows.contexts', 'series']);
            }

            try {
                $this->assertEligible->handle($material);
            } catch (MaterialProfileRejectedException) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }

            $profile = $this->assertProfile->requireReferencedReady($material, (int) $locked->profile_version_id);
            $mode = $locked->mode instanceof BlueprintMode ? $locked->mode : BlueprintMode::Simple;

            if ($mode === BlueprintMode::Advanced && ! $this->resolveActivePro->handle($actor)) {
                throw new BlueprintRejectedException(BlueprintErrorCode::AdvancedRequiresPro);
            }

            $this->assertShape->handle($graph['rows'], $mode);
            $this->assertContexts->handle($material, $profile, $graph['rows'], $graph['contexts']);

            $fingerprint = $this->assertProfile->fingerprint($material);
            $locked->material_content_hash = $fingerprint['material_content_hash'];
            $locked->material_file_hash = $fingerprint['material_file_hash'];
            $locked->extractor_implementation = $fingerprint['extractor_implementation'];
            $locked->lifecycle_status = BlueprintLifecycleStatus::Confirmed;
            $locked->confirmed_at = now();
            $locked->error_code = null;
            $locked->error_message = null;
            $locked->save();

            return $locked->refresh()->load(['rows.contexts', 'series']);
        });
    }
}
