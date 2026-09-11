<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintSource;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionBlueprintRowContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CloneConfirmedBlueprintToDraft
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(private AssertReadyMatchingProfile $assertProfile) {}

    public function handle(User $actor, QuestionBlueprint $blueprint): QuestionBlueprint
    {
        return DB::transaction(function () use ($actor, $blueprint): QuestionBlueprint {
            $material = $this->lockUserAndMaterial((int) $actor->id, (int) $blueprint->material_id);
            $graph = $this->lockBlueprintGraph($blueprint);
            $source = $graph['blueprint'];

            if ((int) $source->user_id !== (int) $actor->id) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }

            if ($source->lifecycle_status !== BlueprintLifecycleStatus::Confirmed) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            $existingDraft = $graph['blueprints']->first(
                fn (QuestionBlueprint $candidate): bool => $candidate->lifecycle_status === BlueprintLifecycleStatus::Draft,
            );

            if ($existingDraft !== null) {
                return $existingDraft->refresh()->load(['rows.contexts', 'series']);
            }

            $profile = $this->assertProfile->requireMatchingReady($material);
            $fingerprint = $this->assertProfile->fingerprint($material);
            $nextVersion = (int) $graph['blueprints']->max('version') + 1;

            $draft = QuestionBlueprint::query()->create([
                'blueprint_series_id' => $source->blueprint_series_id,
                'user_id' => $source->user_id,
                'material_id' => $source->material_id,
                'profile_version_id' => $profile->profile_version_id,
                'version' => $nextVersion,
                'lifecycle_status' => BlueprintLifecycleStatus::Draft,
                'source' => $source->source ?? BlueprintSource::Manual,
                'ai_fill_status' => BlueprintAiFillStatus::None,
                'assessment_type' => $source->assessment_type,
                'title' => $source->title,
                'material_content_hash' => $fingerprint['material_content_hash'],
                'material_file_hash' => $fingerprint['material_file_hash'],
                'extractor_implementation' => $fingerprint['extractor_implementation'],
            ]);

            foreach ($graph['rows'] as $row) {
                $clonedRow = QuestionBlueprintRow::query()->create([
                    'blueprint_id' => $draft->blueprint_id,
                    'sort_order' => $row->sort_order,
                    'objective' => $row->objective,
                    'topic' => $row->topic,
                    'indicator' => $row->indicator,
                    'cognitive_level' => $row->cognitive_level,
                    'difficulty' => $row->difficulty,
                    'question_type' => $row->question_type,
                    'requested_count' => $row->requested_count,
                    'origin' => $row->origin,
                ]);

                foreach ($graph['contexts']->where('blueprint_row_id', $row->blueprint_row_id) as $context) {
                    QuestionBlueprintRowContext::query()->create([
                        'blueprint_row_id' => $clonedRow->blueprint_row_id,
                        'profile_element_id' => $context->profile_element_id,
                        'profile_chunk_id' => $context->profile_chunk_id,
                        'char_start' => $context->char_start,
                        'char_end' => $context->char_end,
                        'context_hash' => $context->context_hash,
                        'rank' => $context->rank,
                    ]);
                }
            }

            return $draft->refresh()->load(['rows.contexts', 'series']);
        });
    }
}
