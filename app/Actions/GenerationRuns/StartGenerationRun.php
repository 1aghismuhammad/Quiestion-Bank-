<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Actions\Generations\AssertMaterialEligibleForGeneration;
use App\Actions\Generations\ResolveGenerationUsage;
use App\Actions\QuestionBlueprints\AssertReadyMatchingProfile;
use App\Actions\QuestionBlueprints\AssertSimpleBlueprintShape;
use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\GenerationRunErrorCode;
use App\Enums\GenerationRunMode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\AiUsageLog;
use App\Models\QuestionBlueprint;
use App\Models\User;
use App\Support\Generations\GenerationCredits;
use App\Support\Generations\UsageSubjectXor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StartGenerationRun
{
    use LocksGenerationRun;

    public function __construct(
        private ResolveUserEntitlement $resolveEntitlement,
        private ResolveGenerationQuota $resolveQuota,
        private ResolveGenerationUsage $resolveUsage,
        private AssertMaterialEligibleForGeneration $assertEligible,
        private AssertReadyMatchingProfile $assertProfile,
        private AssertSimpleBlueprintShape $assertShape,
        private ComputeGenerationRunFingerprint $fingerprint,
        private SelectRunItemSpans $selectSpans,
        private DispatchQueuedRunChild $dispatchQueued,
    ) {}

    public function handle(
        User $actor,
        QuestionBlueprint $blueprint,
        OutputLanguage $outputLanguage,
        string $idempotencyKey,
        ?int $parentRunId = null,
    ): AiGenerationRun {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $idempotencyKey)) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::ValidationFailed);
        }

        $dispatch = null;

        $run = DB::transaction(function () use (
            $actor,
            $blueprint,
            $outputLanguage,
            $idempotencyKey,
            $parentRunId,
            &$dispatch,
        ): AiGenerationRun {
            $owner = $this->lockUser((int) $actor->id);

            $existing = AiGenerationRun::query()
                ->where('user_id', $owner->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            $lockedBlueprint = QuestionBlueprint::query()
                ->with(['rows.contexts'])
                ->whereKey($blueprint->blueprint_id)
                ->firstOrFail();

            if ($lockedBlueprint->lifecycle_status !== BlueprintLifecycleStatus::Confirmed) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::BlueprintNotConfirmed);
            }

            try {
                $this->assertShape->handle($lockedBlueprint->rows);
            } catch (BlueprintRejectedException) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::ValidationFailed);
            }

            $materialPreview = $lockedBlueprint->material()->firstOrFail();
            $fingerprintPreview = $this->assertProfile->fingerprint($materialPreview);
            $total = (int) $lockedBlueprint->rows->sum('requested_count');
            $credits = GenerationCredits::required($total);
            $computedFingerprint = $this->fingerprint->forStart(
                (int) $owner->id,
                $lockedBlueprint,
                $outputLanguage->value,
                $total,
                $credits,
                $fingerprintPreview['material_content_hash'],
                $fingerprintPreview['material_file_hash'],
                $fingerprintPreview['extractor_implementation'],
                $parentRunId,
                $lockedBlueprint->rows,
            );

            if ($existing !== null) {
                if ((string) $existing->request_fingerprint !== $computedFingerprint) {
                    throw new GenerationRunRejectedException(GenerationRunErrorCode::IdempotencyConflict);
                }

                $graph = $this->lockCanonicalRunGraph((int) $existing->generation_run_id);

                if (! $graph['run']->status->isTerminal()) {
                    $processing = $graph['children']->first(
                        fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::PROCESSING,
                    );
                    $queued = $graph['children']
                        ->filter(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::QUEUED)
                        ->sortBy(fn (AiGeneration $child): int => (int) $child->child_index)
                        ->first();

                    if ($processing === null && $queued !== null) {
                        $dispatch = $this->dispatchQueued->prepareNextFromLockedGraph($graph, allowInitialChild: true);
                    }
                }

                return $existing;
            }

            $material = $this->lockMaterial((int) $lockedBlueprint->material_id);

            if ((int) $material->user_id !== (int) $owner->id) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::MaterialIneligible);
            }

            Gate::forUser($owner)->authorize('generate', $material);

            try {
                $this->assertEligible->handle($material);
            } catch (ValidationException) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::MaterialIneligible);
            }

            try {
                $profile = $this->assertProfile->requireReferencedReady(
                    $material,
                    (int) $lockedBlueprint->profile_version_id,
                );
            } catch (BlueprintRejectedException $exception) {
                throw new GenerationRunRejectedException(match ($exception->errorCode) {
                    BlueprintErrorCode::ProfileRequired => GenerationRunErrorCode::ProfileRequired,
                    BlueprintErrorCode::ProfileStale => GenerationRunErrorCode::ProfileStale,
                    BlueprintErrorCode::MaterialIneligible => GenerationRunErrorCode::MaterialIneligible,
                    default => GenerationRunErrorCode::ValidationFailed,
                });
            }

            $this->lockProfileVersion((int) $profile->profile_version_id);
            $lockedBlueprint = $this->lockConfirmedBlueprint((int) $lockedBlueprint->blueprint_id)
                ->load(['rows.contexts']);

            $liveFingerprint = $this->assertProfile->fingerprint($material);

            if (
                (string) $lockedBlueprint->material_content_hash !== $liveFingerprint['material_content_hash']
                || (string) $lockedBlueprint->extractor_implementation !== $liveFingerprint['extractor_implementation']
                || $lockedBlueprint->material_file_hash !== $liveFingerprint['material_file_hash']
            ) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::BlueprintStale);
            }

            $spansByRow = $this->selectSpans->forRows(
                $material,
                $profile,
                $lockedBlueprint->rows,
            );

            $entitlement = $this->resolveEntitlement->handle($owner);
            $quota = $this->resolveQuota->handle($owner, $entitlement);
            $usage = $this->resolveUsage->handle($owner, $quota);

            if ($usage->available < $credits) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::QuotaInsufficient);
            }

            $run = AiGenerationRun::query()->create([
                'user_id' => $owner->id,
                'material_id' => $material->material_id,
                'blueprint_id' => $lockedBlueprint->blueprint_id,
                'blueprint_series_id' => $lockedBlueprint->blueprint_series_id,
                'blueprint_version' => $lockedBlueprint->version,
                'profile_version_id' => $profile->profile_version_id,
                'assessment_type' => $lockedBlueprint->assessment_type,
                'output_language' => $outputLanguage,
                'mode' => GenerationRunMode::Simple,
                'shuffle_questions' => false,
                'shuffle_options' => false,
                'material_content_hash' => $liveFingerprint['material_content_hash'],
                'material_file_hash' => $liveFingerprint['material_file_hash'],
                'extractor_implementation' => $liveFingerprint['extractor_implementation'],
                'total_requested_questions' => $total,
                'credits_required' => $credits,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $computedFingerprint,
                'parent_run_id' => $parentRunId,
                'status' => GenerationRunStatus::Queued,
                'queued_at' => now(),
            ]);

            $childIndex = 1;
            $firstChild = null;

            foreach ($lockedBlueprint->rows as $row) {
                if ($row->question_type !== QuestionType::MULTIPLE_CHOICE || (int) $row->requested_count > 10) {
                    throw new GenerationRunRejectedException(GenerationRunErrorCode::ValidationFailed);
                }

                $item = AiGenerationRunItem::query()->create([
                    'generation_run_id' => $run->generation_run_id,
                    'blueprint_row_id' => $row->blueprint_row_id,
                    'objective' => $row->objective,
                    'topic' => $row->topic,
                    'indicator' => $row->indicator,
                    'cognitive_level' => $row->cognitive_level,
                    'difficulty' => $row->difficulty,
                    'question_type' => $row->question_type,
                    'requested_count' => $row->requested_count,
                    'sort_order' => $row->sort_order,
                ]);

                $this->selectSpans->persist($item, $spansByRow[(int) $row->blueprint_row_id] ?? []);

                $child = AiGeneration::query()->create([
                    'user_id' => $owner->id,
                    'material_id' => $material->material_id,
                    'generation_run_id' => $run->generation_run_id,
                    'generation_run_item_id' => $item->generation_run_item_id,
                    'child_index' => $childIndex,
                    'assessment_type' => $lockedBlueprint->assessment_type,
                    'difficulty_level' => $row->difficulty,
                    'question_type' => $row->question_type,
                    'question_count' => $row->requested_count,
                    'output_language' => $outputLanguage,
                    'generation_status' => GenerationStatus::QUEUED,
                    'queued_at' => null,
                    'execution_token' => null,
                    'attempt_number' => 0,
                ]);

                if ($firstChild === null) {
                    $firstChild = $child;
                }

                $childIndex++;
            }

            UsageSubjectXor::assert(null, (int) $run->generation_run_id);

            AiUsageLog::query()->create([
                'user_id' => $owner->id,
                'plan_id' => $quota->entitlement->plan->plan_id,
                'subscription_id' => $quota->entitlement->subscription?->subscription_id,
                'generation_id' => null,
                'generation_run_id' => $run->generation_run_id,
                'credits' => $credits,
                'status' => UsageStatus::RESERVED,
                'window_start' => $quota->windowStart,
                'window_end' => $quota->windowEnd,
                'reserved_at' => now(),
                'finalized_at' => null,
            ]);

            if ($firstChild !== null) {
                $dispatch = $this->dispatchQueued->prepareLocked($firstChild);
            }

            return $run->refresh();
        });

        $this->dispatchQueued->dispatch($dispatch);

        return $run;
    }
}
