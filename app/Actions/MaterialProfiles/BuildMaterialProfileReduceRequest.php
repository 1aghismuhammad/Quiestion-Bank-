<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileElementSummary;
use App\Data\MaterialProfiles\ProfileReduceRequest;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStepPurpose;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Services\AI\MaterialProfilePromptBuilder;
use App\Support\MaterialProfiles\MaterialProfileBudgets;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the reduce request from every persisted extracted Element.
 *
 * Chunk cores and complete Material content are structurally excluded: the
 * request carries the normalized observation text, a safe locator, and canonical
 * offsets, and nothing else. Silent prefix truncation is forbidden.
 */
class BuildMaterialProfileReduceRequest
{
    use LocksMaterialProfileWorkflow;
    use ResolvesNextMaterialProfileStep;
    use VerifiesMaterialProfileContext;

    public function __construct(
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
        private MaterialContentHasher $hasher,
        private MaterialProfilePromptBuilder $promptBuilder,
    ) {}

    /**
     * @return ProfileReduceRequest|null Null when execution authority has been lost.
     *
     * @throws MaterialProfileRejectedException When the persisted workflow context is invalid.
     */
    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
    ): ?ProfileReduceRequest {
        return DB::transaction(function () use (
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
        ): ?ProfileReduceRequest {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            if ($step === null) {
                return null;
            }

            try {
                $this->assertAuthority->handle($version, $workflowToken, $step, $stepExecutionToken);
            } catch (MaterialProfileRejectedException) {
                return null;
            }

            if ($step->purpose !== MaterialProfileStepPurpose::REDUCE || $step->profile_chunk_id !== null) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $material = $this->lockedMaterialFor($version);
            $this->assertMaterialFingerprint($version, $material, $this->hasher);

            $this->assertEveryMapIsReadyAndSucceeded($steps);

            return new ProfileReduceRequest(
                profileVersionId: (int) $version->profile_version_id,
                summaries: $this->summaries((int) $version->profile_version_id),
                model: $this->model(),
                promptVersion: $this->promptBuilder->reduceVersion(),
            );
        });
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function assertEveryMapIsReadyAndSucceeded(Collection $steps): void
    {
        if (! $this->allMapStepsReady($steps)) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::NotNextStep);
        }

        foreach ($this->orderedMapSteps($steps) as $map) {
            $succeeded = MaterialProfileAttempt::query()
                ->where('profile_step_id', $map->profile_step_id)
                ->where('profile_version_id', $map->profile_version_id)
                ->where('purpose', MaterialProfileStepPurpose::MAP)
                ->where('status', MaterialProfileAttemptStatus::SUCCEEDED)
                ->exists();

            if (! $succeeded) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }
        }
    }

    /**
     * @return list<ProfileElementSummary>
     */
    private function summaries(int $profileVersionId): array
    {
        $elements = MaterialProfileElement::query()
            ->where('profile_version_id', $profileVersionId)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->orderBy('sort_order')
            ->orderBy('profile_element_id')
            ->get(['kind', 'text', 'evidence_locator', 'char_start', 'char_end']);

        if ($elements->isEmpty()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ($elements->count() > MaterialProfileBudgets::maxReduceSummaries()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        return $elements
            ->map(fn (MaterialProfileElement $element): ProfileElementSummary => new ProfileElementSummary(
                kind: $element->kind,
                text: (string) $element->text,
                evidenceLocator: $element->evidence_locator === null ? null : (string) $element->evidence_locator,
                charStart: $element->char_start === null ? null : (int) $element->char_start,
                charEnd: $element->char_end === null ? null : (int) $element->char_end,
            ))
            ->values()
            ->all();
    }

    private function model(): string
    {
        $model = config('material_profile.primary_model');

        if (! is_string($model) || $model === '') {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        return $model;
    }
}
