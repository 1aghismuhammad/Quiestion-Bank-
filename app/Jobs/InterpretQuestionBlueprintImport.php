<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\QuestionBlueprints\ProcessQuestionBlueprintImportInterpretation;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Models\QuestionBlueprintImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

class InterpretQuestionBlueprintImport implements ShouldQueue
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
            (new WithoutOverlapping('blueprint-import-interpretation:'.$this->importId))
                ->releaseAfter(60)
                ->expireAfter(330),
        ];
    }

    public function handle(ProcessQuestionBlueprintImportInterpretation $processor): void
    {
        $processor->handle($this->importId, $this->queuedAt);
    }

    public function failed(?Throwable $exception): void
    {
        $expectedQueuedAt = Carbon::parse($this->queuedAt);
        $cycle = [
            'import_id' => $this->importId,
            'interpretation_queued_at' => $expectedQueuedAt,
        ];
        $liveStatuses = [
            BlueprintImportInterpretationStatus::QUEUED,
            BlueprintImportInterpretationStatus::PROCESSING,
        ];

        $preserved = QuestionBlueprintImport::query()
            ->where($cycle)
            ->whereIn('interpretation_status', $liveStatuses)
            ->whereNotNull('interpretation_error_code')
            ->update([
                'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
                'interpretation_completed_at' => now(),
            ]);

        if ($preserved !== 0) {
            return;
        }

        QuestionBlueprintImport::query()
            ->where($cycle)
            ->whereIn('interpretation_status', $liveStatuses)
            ->whereNull('interpretation_error_code')
            ->update([
                'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
                'interpretation_error_code' => ProcessQuestionBlueprintImportInterpretation::ERROR_UNEXPECTED,
                'interpretation_error_message' => app(ProcessQuestionBlueprintImportInterpretation::class)
                    ->publicMessage(ProcessQuestionBlueprintImportInterpretation::ERROR_UNEXPECTED),
                'interpretation_completed_at' => now(),
            ]);
    }
}
