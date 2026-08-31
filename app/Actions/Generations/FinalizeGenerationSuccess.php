<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use Illuminate\Support\Facades\DB;

class FinalizeGenerationSuccess
{
    use LocksGenerationExecution;
    use LocksStoredGenerationUsage;

    public function __construct(private ConsumeGenerationCredit $consume) {}

    public function handle(int $generationId, string $executionToken, ValidatedMcqSet $questions): AiGeneration
    {
        return DB::transaction(function () use ($generationId, $executionToken, $questions): AiGeneration {
            $generation = $this->lockUserAndGeneration($generationId);
            $usage = $this->lockStoredReservation($generation);

            if (
                $generation->generation_status === GenerationStatus::COMPLETED
                && $usage->status === UsageStatus::CHARGED
            ) {
                return $generation;
            }

            if ($generation->generation_status === GenerationStatus::FAILED) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $generation->user_id,
                    (int) $generation->generation_id,
                    $usage->usage_id,
                );
            }

            $this->assertOwnedProcessing($generation, $executionToken);

            if ($usage->status !== UsageStatus::RESERVED) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $generation->user_id,
                    (int) $generation->generation_id,
                    $usage->usage_id,
                );
            }

            if ($questions->count() !== (int) $generation->question_count) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $generation->user_id,
                    (int) $generation->generation_id,
                    $usage->usage_id,
                );
            }

            $aggregates = $this->aggregates((int) $generation->generation_id);

            $generation->result_json = $questions->toArray();
            $generation->provider_name = $aggregates['provider_name'];
            $generation->model_name = $aggregates['model_name'];
            $generation->input_tokens = $aggregates['input_tokens'];
            $generation->output_tokens = $aggregates['output_tokens'];
            $generation->error_code = null;
            $generation->error_message = null;
            $generation->completed_at = now();
            $generation->failed_at = null;
            $generation->generation_status = GenerationStatus::COMPLETED;
            $generation->save();

            $this->consume->handle($generation);

            return $generation->refresh();
        });
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
