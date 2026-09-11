<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Data\GenerationRuns\DispatchGenerationRunChild;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Events\GenerationRunChildDispatchRequested;
use App\Exceptions\GenerationRuns\GenerationRunTopologyException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\AiUsageLog;
use App\Support\Generations\ResolvesGenerationRunStaleCutoff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class DispatchQueuedRunChild
{
    use ResolvesGenerationRunStaleCutoff;

    public function __construct(private AssertRunChildTopology $assertTopology) {}

    /**
     * Persist the authoritative delivery token on a locked queued child.
     * The abandonment clock starts only when the first token is minted.
     * Redispatch with a stored token must not reset queued_at.
     * Callers must already hold the canonical Run graph locks.
     */
    public function prepareLocked(AiGeneration $queuedChild): DispatchGenerationRunChild
    {
        if ($queuedChild->generation_status !== GenerationStatus::QUEUED) {
            throw new GenerationRunTopologyException(GenerationErrorCode::TopologyInvalid);
        }

        $token = (string) ($queuedChild->execution_token ?? '');

        if ($token === '') {
            $token = (string) Str::uuid();
            $queuedChild->execution_token = $token;
            $queuedChild->queued_at ??= now();
            $queuedChild->save();
        }

        return new DispatchGenerationRunChild(
            (int) $queuedChild->generation_id,
            $token,
        );
    }

    /**
     * @param  array{
     *     run: AiGenerationRun,
     *     items: Collection<int, AiGenerationRunItem>,
     *     children: Collection<int, AiGeneration>,
     *     usage: AiUsageLog
     * }  $graph
     */
    public function prepareNextFromLockedGraph(array $graph, bool $allowInitialChild = false): ?DispatchGenerationRunChild
    {
        $run = $graph['run'];
        $items = $graph['items'];
        $children = $graph['children'];
        $usage = $graph['usage'];

        if ($run->status->isTerminal() || $usage->status !== UsageStatus::RESERVED) {
            return null;
        }

        $processing = $children->first(
            fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::PROCESSING,
        );

        if ($processing !== null) {
            return null;
        }

        $next = $children
            ->filter(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::QUEUED)
            ->sortBy(fn (AiGeneration $child): int => (int) $child->child_index)
            ->first();

        if ($next === null || $this->runChildAuthorityExpired($next)) {
            return null;
        }

        $priors = $children->filter(
            fn (AiGeneration $child): bool => (int) $child->child_index < (int) $next->child_index,
        );

        if ($priors->isEmpty() && ! $allowInitialChild) {
            return null;
        }

        if ($priors->contains(
            fn (AiGeneration $child): bool => $child->generation_status !== GenerationStatus::COMPLETED,
        )) {
            return null;
        }

        try {
            $this->assertTopology->handle(
                $run,
                $items,
                $children,
                $usage,
                null,
                requireProcessingRun: $run->status === GenerationRunStatus::Processing,
            );
        } catch (GenerationRunTopologyException) {
            return null;
        }

        return $this->prepareLocked($next);
    }

    public function dispatch(?DispatchGenerationRunChild $prepared): void
    {
        if ($prepared === null) {
            return;
        }

        event(new GenerationRunChildDispatchRequested(
            $prepared->generationId,
            $prepared->executionToken,
        ));

        GenerateQuestionsJob::dispatch($prepared->generationId, $prepared->executionToken);
    }
}
