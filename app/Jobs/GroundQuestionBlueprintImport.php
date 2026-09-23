<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\QuestionBlueprints\ProcessQuestionBlueprintImportGrounding;
use App\Enums\BlueprintImportGroundingStatus;
use App\Models\QuestionBlueprintImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

class GroundQuestionBlueprintImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 270;

    public bool $failOnTimeout = false;

    public function __construct(
        public int $importId,
        public string $queuedAt,
    ) {
        $this->timeout = (int) config('question_blueprint.job_timeout_seconds', 270);
        $this->onConnection((string) config('question_blueprint.queue_connection', 'database-generation'));
        $this->onQueue((string) config('question_blueprint.queue', 'material-intelligence'));
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = config('question_blueprint.backoff_seconds', [5, 15]);

        return is_array($backoff)
            ? array_values(array_map(static fn (mixed $seconds): int => (int) $seconds, $backoff))
            : [5, 15];
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('blueprint-import-grounding:'.$this->importId))
                ->releaseAfter(60)
                ->expireAfter(330),
        ];
    }

    public function handle(ProcessQuestionBlueprintImportGrounding $processor): void
    {
        $processor->handle($this->importId, $this->queuedAt);
    }

    /**
     * Laravel invokes this via CallQueuedHandler::failed(), which re-hydrates the
     * command from the original queue payload — not the in-memory handle() instance.
     *
     * Mutable handle()-time state (e.g. claimed_at) is therefore unavailable here.
     * Without stable serialized claim ownership, PROCESSING must not be mutated.
     * PROCESSING recovery stays with stale claim reclaim + Process CAS paths.
     */
    public function failed(?Throwable $exception): void
    {
        $expectedQueuedAt = Carbon::parse($this->queuedAt);
        $cycle = [
            'import_id' => $this->importId,
            'grounding_queued_at' => $expectedQueuedAt,
        ];

        $preservedQueued = QuestionBlueprintImport::query()
            ->where($cycle)
            ->where('grounding_status', BlueprintImportGroundingStatus::QUEUED)
            ->whereNull('grounding_claimed_at')
            ->whereNotNull('grounding_error_code')
            ->update([
                'grounding_status' => BlueprintImportGroundingStatus::FAILED,
                'grounding_completed_at' => now(),
            ]);

        if ($preservedQueued !== 0) {
            return;
        }

        QuestionBlueprintImport::query()
            ->where($cycle)
            ->where('grounding_status', BlueprintImportGroundingStatus::QUEUED)
            ->whereNull('grounding_claimed_at')
            ->whereNull('grounding_error_code')
            ->update([
                'grounding_status' => BlueprintImportGroundingStatus::FAILED,
                'grounding_error_code' => ProcessQuestionBlueprintImportGrounding::ERROR_UNEXPECTED,
                'grounding_error_message' => app(ProcessQuestionBlueprintImportGrounding::class)
                    ->publicMessage(ProcessQuestionBlueprintImportGrounding::ERROR_UNEXPECTED),
                'grounding_completed_at' => now(),
            ]);
    }
}
