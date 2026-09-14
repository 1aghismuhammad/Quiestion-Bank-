<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Actions\MaterialProfiles\AssertMaterialEligibleForProfileAnalysis;
use App\Actions\Subscriptions\ResolveActivePro;
use App\Data\QuestionBlueprints\BlueprintFillDispatch;
use App\Data\QuestionBlueprints\BlueprintFillTypeCounts;
use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Enums\BlueprintSource;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintFillEvent;
use App\Models\QuestionBlueprintSeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueBlueprintAiFill
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
        private AssertReadyMatchingProfile $assertProfile,
        private ResolveActivePro $resolveActivePro,
    ) {}

    public function handle(
        User $actor,
        Material $material,
        ?QuestionBlueprint $existingDraft = null,
        ?string $title = null,
        ?AssessmentType $assessmentType = null,
        ?BlueprintMode $requestedMode = null,
        ?int $requestedTotal = null,
        ?array $requestedTypeCounts = null,
    ): QuestionBlueprint {
        $dispatch = null;

        $blueprint = DB::transaction(function () use (
            $actor,
            $material,
            $existingDraft,
            $title,
            $assessmentType,
            $requestedMode,
            $requestedTotal,
            $requestedTypeCounts,
            &$dispatch,
        ): QuestionBlueprint {
            $lockedMaterial = $this->lockUserAndMaterial((int) $actor->id, (int) $material->material_id);
            try {
                $this->assertEligible->handle($lockedMaterial);
            } catch (MaterialProfileRejectedException) {
                throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
            }
            $profile = $this->assertProfile->requireMatchingReady($lockedMaterial);
            $this->lockProfileVersion((int) $profile->profile_version_id);

            $this->assertNoInFlightFill((int) $lockedMaterial->material_id);
            $this->assertThrottleAvailable((int) $lockedMaterial->user_id);

            $draft = $existingDraft === null
                ? $this->createEmptyAiDraft(
                    $lockedMaterial,
                    $profile->profile_version_id,
                    $title,
                    $assessmentType,
                    $this->resolveNewFillMode($actor, $requestedMode),
                )
                : $this->prepareExistingDraft($existingDraft, $lockedMaterial, $profile->profile_version_id, $actor);

            $mode = $draft->mode instanceof BlueprintMode ? $draft->mode : BlueprintMode::Simple;
            $target = $this->resolveFillTarget($mode, $existingDraft === null, $requestedTotal, $draft);
            $typeCounts = $this->resolveFillTypeCounts(
                $mode,
                $existingDraft === null,
                $requestedTypeCounts,
                $draft,
                $target,
            );

            $workflowToken = (string) Str::uuid();
            $stepToken = (string) Str::uuid();
            $now = now();
            $fingerprint = $this->assertProfile->fingerprint($lockedMaterial);

            $draft->source = BlueprintSource::Ai;
            $draft->ai_fill_status = BlueprintAiFillStatus::Queued;
            $draft->lifecycle_status = BlueprintLifecycleStatus::Draft;
            $draft->profile_version_id = $profile->profile_version_id;
            $draft->material_content_hash = $fingerprint['material_content_hash'];
            $draft->material_file_hash = $fingerprint['material_file_hash'];
            $draft->extractor_implementation = $fingerprint['extractor_implementation'];
            $draft->workflow_token = $workflowToken;
            $draft->step_execution_token = $stepToken;
            $draft->ai_fill_requested_total = $target;
            $draft->ai_fill_requested_type_counts = $typeCounts?->toArray();
            $draft->queued_at = $now;
            $draft->claimed_at = null;
            $draft->heartbeat_at = null;
            $draft->lease_expires_at = null;
            $draft->error_code = null;
            $draft->error_message = null;
            $draft->save();

            QuestionBlueprintFillEvent::query()->firstOrCreate(
                ['queue_request_key' => hash('sha256', $workflowToken)],
                [
                    'user_id' => (int) $lockedMaterial->user_id,
                    'blueprint_id' => (int) $draft->blueprint_id,
                    'accepted_at' => $now,
                ],
            );

            $dispatch = new BlueprintFillDispatch(
                (int) $draft->blueprint_id,
                $workflowToken,
                $stepToken,
            );

            return $draft->refresh();
        });

        if ($dispatch !== null) {
            FillQuestionBlueprintJob::dispatch(
                $dispatch->blueprintId,
                $dispatch->workflowToken,
                $dispatch->stepExecutionToken,
            );
        }

        return $blueprint;
    }

    private function createEmptyAiDraft(
        Material $material,
        int $profileVersionId,
        ?string $title,
        ?AssessmentType $assessmentType,
        BlueprintMode $mode,
    ): QuestionBlueprint {
        $resolvedTitle = trim((string) ($title ?: 'Kisi-kisi AI'));
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);

        if ($resolvedTitle === '' || mb_strlen($resolvedTitle, 'UTF-8') > $maxTitle) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        $series = QuestionBlueprintSeries::query()->create([
            'user_id' => $material->user_id,
            'material_id' => $material->material_id,
        ]);

        $fingerprint = $this->assertProfile->fingerprint($material);

        return QuestionBlueprint::query()->create([
            'blueprint_series_id' => $series->blueprint_series_id,
            'user_id' => $material->user_id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profileVersionId,
            'version' => 1,
            'lifecycle_status' => BlueprintLifecycleStatus::Draft,
            'source' => BlueprintSource::Ai,
            'ai_fill_status' => BlueprintAiFillStatus::None,
            'mode' => $mode,
            'assessment_type' => $assessmentType ?? AssessmentType::FORMATIVE,
            'title' => $resolvedTitle,
            'material_content_hash' => $fingerprint['material_content_hash'],
            'material_file_hash' => $fingerprint['material_file_hash'],
            'extractor_implementation' => $fingerprint['extractor_implementation'],
        ]);
    }

    private function prepareExistingDraft(
        QuestionBlueprint $existing,
        Material $material,
        int $profileVersionId,
        User $actor,
    ): QuestionBlueprint {
        $this->lockSeries((int) $existing->blueprint_series_id);
        $blueprints = $this->lockBlueprintsAscending((int) $existing->blueprint_series_id);
        $draft = $blueprints->first(
            fn (QuestionBlueprint $candidate): bool => (int) $candidate->blueprint_id === (int) $existing->blueprint_id,
        );

        if ($draft === null || (int) $draft->material_id !== (int) $material->material_id) {
            throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
        }

        if ($draft->lifecycle_status !== BlueprintLifecycleStatus::Draft) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ConfirmedImmutable);
        }

        if ($draft->ai_fill_status->isInFlight()) {
            throw new BlueprintRejectedException(BlueprintErrorCode::InFlightExists);
        }

        $rows = $this->lockRowsAscending((int) $draft->blueprint_id);

        if ($rows->isNotEmpty()) {
            throw new BlueprintRejectedException(BlueprintErrorCode::RowsNotEmpty);
        }

        if ($draft->ai_fill_status === BlueprintAiFillStatus::Succeeded) {
            throw new BlueprintRejectedException(BlueprintErrorCode::RowsNotEmpty);
        }

        $mode = $draft->mode instanceof BlueprintMode ? $draft->mode : BlueprintMode::Simple;

        if ($mode === BlueprintMode::Advanced && ! $this->resolveActivePro->handle($actor)) {
            throw new BlueprintRejectedException(BlueprintErrorCode::AdvancedRequiresPro);
        }

        $draft->profile_version_id = $profileVersionId;

        return $draft;
    }

    private function assertNoInFlightFill(int $materialId): void
    {
        $inFlight = QuestionBlueprint::query()
            ->where('material_id', $materialId)
            ->whereIn('ai_fill_status', [
                BlueprintAiFillStatus::Queued->value,
                BlueprintAiFillStatus::Processing->value,
            ])
            ->lockForUpdate()
            ->exists();

        if ($inFlight) {
            throw new BlueprintRejectedException(BlueprintErrorCode::InFlightExists);
        }
    }

    private function assertThrottleAvailable(int $userId): void
    {
        $limit = max(0, (int) config('question_blueprint.new_fills_per_hour', 3));
        $windowSeconds = max(1, (int) config('question_blueprint.throttle_window_seconds', 3600));

        $queued = QuestionBlueprintFillEvent::query()
            ->where('user_id', $userId)
            ->where('accepted_at', '>', now()->subSeconds($windowSeconds))
            ->lockForUpdate()
            ->count();

        if ($queued >= $limit) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ThrottleExceeded);
        }
    }

    private function resolveNewFillMode(User $actor, ?BlueprintMode $requestedMode): BlueprintMode
    {
        $mode = $requestedMode ?? BlueprintMode::Simple;

        if ($mode === BlueprintMode::Advanced && ! $this->resolveActivePro->handle($actor)) {
            throw new BlueprintRejectedException(BlueprintErrorCode::AdvancedRequiresPro);
        }

        return $mode;
    }

    private function resolveFillTarget(
        BlueprintMode $mode,
        bool $isNewFill,
        ?int $requestedTotal,
        QuestionBlueprint $draft,
    ): ?int {
        if ($mode === BlueprintMode::Simple) {
            return null;
        }

        $max = (int) config('question_blueprint.max_advanced_total_requested', 30);
        $target = $isNewFill
            ? $requestedTotal
            : ($requestedTotal ?? $draft->ai_fill_requested_total);

        if ($target === null || $target < 1 || $target > $max) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>|null  $requestedTypeCounts
     */
    private function resolveFillTypeCounts(
        BlueprintMode $mode,
        bool $isNewFill,
        ?array $requestedTypeCounts,
        QuestionBlueprint $draft,
        ?int $target,
    ): ?BlueprintFillTypeCounts {
        $raw = $isNewFill
            ? $requestedTypeCounts
            : ($requestedTypeCounts ?? $draft->ai_fill_requested_type_counts);

        if ($raw === null) {
            return null;
        }

        $counts = BlueprintFillTypeCounts::parse($raw);
        $counts->assertCompatibleWith($mode);

        if ($mode === BlueprintMode::Advanced && $target !== null && $counts->total() !== $target) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return $counts;
    }
}
