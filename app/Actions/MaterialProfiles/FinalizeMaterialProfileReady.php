<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FinalizeMaterialProfileReady
{
    use LocksMaterialProfileWorkflow;

    public function __construct(
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
        private MaterialContentHasher $hasher,
    ) {}

    public function handle(int $profileVersionId, string $workflowToken): MaterialProfileVersion
    {
        return DB::transaction(function () use ($profileVersionId, $workflowToken): MaterialProfileVersion {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $chunks = $this->lockChunksAscending($profileVersionId);
            $material = Material::query()
                ->withTrashed()
                ->whereKey($version->material_id)
                ->firstOrFail();

            if ($version->status === MaterialProfileStatus::READY) {
                return $version;
            }

            $this->assertAuthority->handle($version, $workflowToken);
            $this->assertOwnership($version, $material);
            $this->assertReadyInvariants($version, $material, $steps, $chunks);

            $now = now();
            $version->status = MaterialProfileStatus::READY;
            $version->completed_at = $now;
            $version->failed_at = null;
            $version->error_code = null;
            $version->error_message = null;
            $version->save();

            foreach ($steps as $step) {
                $step->lease_expires_at = null;
                $step->save();
            }

            return $version->refresh();
        });
    }

    private function assertOwnership(MaterialProfileVersion $version, Material $material): void
    {
        if ((int) $version->material_id !== (int) $material->material_id
            || (int) $version->user_id !== (int) $material->user_id) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     * @param  Collection<int, MaterialProfileChunk>  $chunks
     */
    private function assertReadyInvariants(
        MaterialProfileVersion $version,
        Material $material,
        Collection $steps,
        Collection $chunks,
    ): void {
        $content = is_string($material->content) ? $material->content : '';

        if ($this->hasher->hash($content) !== (string) $version->material_content_hash) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::HashMismatch);
        }

        $mapSteps = $steps
            ->filter(fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::MAP)
            ->values();
        $reduceSteps = $steps
            ->filter(fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::REDUCE)
            ->values();
        $requiredChunks = $chunks
            ->filter(fn (MaterialProfileChunk $chunk): bool => $chunk->required === true)
            ->values();

        if ($mapSteps->isEmpty()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ($reduceSteps->count() !== 1) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $this->assertRequiredChunkStructure($version, $content, $requiredChunks);

        foreach ($requiredChunks as $chunk) {
            $matching = $mapSteps->first(
                fn (MaterialProfileStep $step): bool => (int) $step->profile_chunk_id === (int) $chunk->profile_chunk_id,
            );

            if ($matching === null) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }
        }

        foreach ($mapSteps as $mapStep) {
            $this->assertReadyMapStep($version, $mapStep, $requiredChunks);
        }

        $reduce = $reduceSteps->first();
        $this->assertReadyReduceStep($version, $reduce);

        $elements = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->orderBy('sort_order')
            ->lockForUpdate()
            ->get();

        if ($elements->isEmpty()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $this->assertElementInvariants($version, $content, $chunks, $elements);
    }

    /**
     * @param  Collection<int, MaterialProfileChunk>  $requiredChunks
     */
    private function assertRequiredChunkStructure(
        MaterialProfileVersion $version,
        string $content,
        Collection $requiredChunks,
    ): void {
        $coreMax = (int) config('material_profile.chunk_core_max_chars');
        $overlap = (int) config('material_profile.chunk_overlap_chars');
        $length = mb_strlen($content, 'UTF-8');
        $ordered = $requiredChunks
            ->sortBy(fn (MaterialProfileChunk $chunk): int => (int) $chunk->chunk_index)
            ->values();

        if ($ordered->isEmpty()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        foreach ($ordered as $index => $chunk) {
            if ((int) $chunk->profile_version_id !== (int) $version->profile_version_id
                || (int) $chunk->chunk_index !== $index) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $start = (int) $chunk->char_start;
            $end = (int) $chunk->char_end;

            if ($end <= $start || ($end - $start) > $coreMax) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            if ($index === 0) {
                if ($start !== 0
                    || $chunk->overlap_before_start !== null
                    || $chunk->overlap_before_end !== null) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }
            } else {
                /** @var MaterialProfileChunk $previous */
                $previous = $ordered[$index - 1];

                if ($start !== (int) $previous->char_end) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }

                $previousLength = (int) $previous->char_end - (int) $previous->char_start;
                $expectedOverlap = min($overlap, $previousLength);

                if ($expectedOverlap <= 0) {
                    if ($chunk->overlap_before_start !== null || $chunk->overlap_before_end !== null) {
                        throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                    }
                } elseif ((int) $chunk->overlap_before_end !== $start
                    || (int) $chunk->overlap_before_start !== $start - $expectedOverlap) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }
            }

            $this->assertChunkHash($content, $chunk);
        }

        /** @var MaterialProfileChunk $last */
        $last = $ordered->last();

        if ((int) $last->char_end !== $length) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    /**
     * @param  Collection<int, MaterialProfileChunk>  $requiredChunks
     */
    private function assertReadyMapStep(
        MaterialProfileVersion $version,
        MaterialProfileStep $mapStep,
        Collection $requiredChunks,
    ): void {
        if ((string) $mapStep->workflow_token !== (string) $version->workflow_token
            || $mapStep->status !== MaterialProfileStepStatus::READY
            || $mapStep->profile_chunk_id === null) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $chunk = $requiredChunks->first(
            fn (MaterialProfileChunk $candidate): bool => (int) $candidate->profile_chunk_id === (int) $mapStep->profile_chunk_id,
        );

        if ($chunk === null
            || (int) $chunk->profile_version_id !== (int) $version->profile_version_id
            || $chunk->required !== true
            || (int) $mapStep->step_index !== (int) $chunk->chunk_index) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if (! $this->hasSucceededAttempt($mapStep)) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    private function assertReadyReduceStep(MaterialProfileVersion $version, ?MaterialProfileStep $reduce): void
    {
        if ($reduce === null
            || $reduce->status !== MaterialProfileStepStatus::READY
            || $reduce->profile_chunk_id !== null
            || (int) $reduce->step_index !== 0
            || (string) $reduce->workflow_token !== (string) $version->workflow_token
            || ! $this->hasSucceededAttempt($reduce)) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    /**
     * @param  Collection<int, MaterialProfileChunk>  $chunks
     * @param  Collection<int, MaterialProfileElement>  $elements
     */
    private function assertElementInvariants(
        MaterialProfileVersion $version,
        string $content,
        Collection $chunks,
        Collection $elements,
    ): void {
        $length = mb_strlen($content, 'UTF-8');
        $seenSortOrders = [];

        foreach ($elements as $element) {
            if (in_array((int) $element->sort_order, $seenSortOrders, true)) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $seenSortOrders[] = (int) $element->sort_order;

            if (trim((string) $element->text) === '') {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $chunk = null;

            if ($element->source_chunk_id !== null) {
                $chunk = $chunks->first(
                    fn (MaterialProfileChunk $candidate): bool => (int) $candidate->profile_chunk_id === (int) $element->source_chunk_id,
                );

                if ($chunk === null || (int) $chunk->profile_version_id !== (int) $version->profile_version_id) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }
            }

            if ($element->origin === MaterialProfileElementOrigin::EXTRACTED && $chunk === null) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            if ($element->char_start !== null || $element->char_end !== null) {
                if ($element->char_start === null || $element->char_end === null) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }

                $start = (int) $element->char_start;
                $end = (int) $element->char_end;

                if ($start < 0 || $end <= $start || $end > $length) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }

                if ($chunk !== null && ($start < (int) $chunk->char_start || $end > (int) $chunk->char_end)) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }

                $excerpt = mb_substr($content, $start, $end - $start, 'UTF-8');

                if (is_string($element->evidence_excerpt) && $element->evidence_excerpt !== $excerpt) {
                    throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
                }
            }
        }
    }

    private function hasSucceededAttempt(MaterialProfileStep $step): bool
    {
        return MaterialProfileAttempt::query()
            ->where('profile_step_id', $step->profile_step_id)
            ->where('profile_version_id', $step->profile_version_id)
            ->where('status', MaterialProfileAttemptStatus::SUCCEEDED)
            ->where('purpose', $step->purpose)
            ->exists();
    }

    private function assertChunkHash(string $content, MaterialProfileChunk $chunk): void
    {
        $core = mb_substr(
            $content,
            (int) $chunk->char_start,
            (int) $chunk->char_end - (int) $chunk->char_start,
            'UTF-8',
        );

        if ($this->hasher->hash($core) !== (string) $chunk->core_text_hash) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::HashMismatch);
        }
    }
}
