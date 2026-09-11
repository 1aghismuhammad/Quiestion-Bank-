<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Actions\QuestionBlueprints\AssertReadyMatchingProfile;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Exceptions\GenerationRuns\GenerationRunChildAuthorityInvalidException;
use App\Exceptions\GenerationRuns\GenerationRunTopologyException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Support\Generations\ResolvesGenerationRunStaleCutoff;
use Illuminate\Database\Eloquent\Collection;

class AssertRunChildLiveAuthority
{
    use ResolvesGenerationRunStaleCutoff;

    public function __construct(
        private AssertReadyMatchingProfile $assertProfile,
        private ReconstructRunItemSpans $reconstructSpans,
        private AssertRunChildTopology $assertTopology,
    ) {}

    /**
     * Assumes the canonical post-provider persistence graph is locked:
     * User → Material → Run → items → children → usage → Attempts → Profile Version → spans → elements/chunks.
     *
     * @param  array{
     *     run: AiGenerationRun,
     *     material: Material,
     *     items: Collection<int, AiGenerationRunItem>,
     *     children: Collection<int, AiGeneration>,
     *     usage: AiUsageLog
     * }  $graph
     */
    public function handle(
        array $graph,
        AiGeneration $child,
        string $executionToken,
        bool $requireProcessing = true,
        bool $requireUnexpired = true,
        bool $requireFingerprintsAndSpans = true,
        bool $requireTopology = true,
    ): void {
        $locked = $graph['children']->first(
            fn (AiGeneration $candidate): bool => (int) $candidate->generation_id === (int) $child->generation_id,
        );

        if ($locked === null) {
            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                (int) $child->generation_id,
                $executionToken,
            );
        }

        if ((string) $locked->execution_token !== $executionToken) {
            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                (int) $locked->generation_id,
                $executionToken,
            );
        }

        if ($requireProcessing && $locked->generation_status !== GenerationStatus::PROCESSING) {
            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                (int) $locked->generation_id,
                $executionToken,
            );
        }

        if ($requireUnexpired && $this->runChildAuthorityExpired($locked)) {
            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                (int) $locked->generation_id,
                $executionToken,
            );
        }

        if ($requireTopology) {
            try {
                $this->assertTopology->handle(
                    $graph['run'],
                    $graph['items'],
                    $graph['children'],
                    $graph['usage'],
                    $requireProcessing ? $locked : null,
                );
            } catch (GenerationRunTopologyException $exception) {
                throw new GenerationRunChildAuthorityInvalidException(
                    $exception->errorCode,
                    workerMayFinalizeFailure: true,
                );
            }
        }

        if (! $requireFingerprintsAndSpans) {
            return;
        }

        $run = $graph['run'];
        $material = $graph['material'];
        $live = $this->assertProfile->fingerprint($material);

        if (
            (string) $run->material_content_hash !== $live['material_content_hash']
            || $run->material_file_hash !== $live['material_file_hash']
            || (string) $run->extractor_implementation !== $live['extractor_implementation']
        ) {
            throw new GenerationRunChildAuthorityInvalidException(
                GenerationErrorCode::BlueprintStale,
                workerMayFinalizeFailure: true,
            );
        }

        try {
            $this->assertProfile->requireReferencedReady($material, (int) $run->profile_version_id);
        } catch (BlueprintRejectedException) {
            throw new GenerationRunChildAuthorityInvalidException(
                GenerationErrorCode::BlueprintStale,
                workerMayFinalizeFailure: true,
            );
        }

        foreach ($graph['items'] as $item) {
            $item->unsetRelation('spans');
            $reconstructed = $this->reconstructSpans->handle($run, $item, $material);

            if ($reconstructed['error'] !== null) {
                throw new GenerationRunChildAuthorityInvalidException(
                    $reconstructed['error'],
                    workerMayFinalizeFailure: true,
                );
            }
        }
    }
}
