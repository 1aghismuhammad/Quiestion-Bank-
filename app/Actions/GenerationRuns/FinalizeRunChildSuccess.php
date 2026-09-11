<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationStatus;
use App\Exceptions\GenerationRuns\GenerationRunChildAuthorityInvalidException;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use Illuminate\Support\Facades\DB;

class FinalizeRunChildSuccess
{
    use LocksGenerationRun;

    public function __construct(
        private TerminalizeGenerationRun $terminalize,
        private AssertRunChildLiveAuthority $assertAuthority,
        private DispatchQueuedRunChild $dispatchQueued,
    ) {}

    public function handle(int $generationId, string $executionToken, ValidatedMcqSet $questions): AiGeneration
    {
        $dispatch = null;

        $child = DB::transaction(function () use ($generationId, $executionToken, $questions, &$dispatch): AiGeneration {
            $child = AiGeneration::query()->whereKey($generationId)->firstOrFail();
            $graph = $this->lockCanonicalRunPersistenceGraph((int) $child->generation_run_id);
            $run = $graph['run'];
            $children = $graph['children'];
            $usage = $graph['usage'];
            $items = $graph['items'];
            $attempts = $graph['attempts'];

            $locked = $children->first(
                fn (AiGeneration $candidate): bool => (int) $candidate->generation_id === $generationId,
            );

            if ($locked === null) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    generationId: $generationId,
                );
            }

            if ($locked->generation_status === GenerationStatus::COMPLETED) {
                return $locked;
            }

            try {
                $this->assertAuthority->handle($graph, $locked, $executionToken);
            } catch (GenerationRunChildAuthorityInvalidException $exception) {
                $locked->error_code = $exception->errorCode->value;
                $locked->error_message = $exception->errorCode->userMessage();
                $locked->failed_at = now();
                $locked->completed_at = null;
                $locked->generation_status = GenerationStatus::FAILED;
                $locked->save();
                $this->terminalize->apply($run->refresh(), $children, $usage, $items, $attempts);

                return $locked->refresh();
            }

            if ($questions->count() !== (int) $locked->question_count) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $locked->user_id,
                    (int) $locked->generation_id,
                );
            }

            $aggregates = $this->aggregates((int) $locked->generation_id);

            $locked->result_json = $questions->toArray();
            $locked->provider_name = $aggregates['provider_name'];
            $locked->model_name = $aggregates['model_name'];
            $locked->input_tokens = $aggregates['input_tokens'];
            $locked->output_tokens = $aggregates['output_tokens'];
            $locked->error_code = null;
            $locked->error_message = null;
            $locked->completed_at = now();
            $locked->failed_at = null;
            $locked->generation_status = GenerationStatus::COMPLETED;
            $locked->save();

            $this->terminalize->apply($run, $children, $usage, $items, $attempts);
            $dispatch = $this->dispatchQueued->prepareNextFromLockedGraph([
                'run' => $run->refresh(),
                'items' => $items,
                'children' => $children,
                'usage' => $usage,
            ]);

            return $locked->refresh();
        });

        $this->dispatchQueued->dispatch($dispatch);

        return $child;
    }

    /**
     * @return array{provider_name: ?string, model_name: ?string, input_tokens: ?int, output_tokens: ?int}
     */
    private function aggregates(int $generationId): array
    {
        $attempts = AiGenerationAttempt::query()
            ->where('generation_id', $generationId)
            ->orderBy('attempt_number')
            ->get();

        $last = $attempts->last();
        $input = $attempts->sum(fn (AiGenerationAttempt $attempt): int => (int) $attempt->input_tokens);
        $output = $attempts->sum(fn (AiGenerationAttempt $attempt): int => (int) $attempt->output_tokens);

        return [
            'provider_name' => $last?->provider,
            'model_name' => $last?->model,
            'input_tokens' => $attempts->isEmpty() ? null : $input,
            'output_tokens' => $attempts->isEmpty() ? null : $output,
        ];
    }
}
