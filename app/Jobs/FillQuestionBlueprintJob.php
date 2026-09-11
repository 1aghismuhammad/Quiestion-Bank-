<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\QuestionBlueprints\FailBlueprintAttemptAndWorkflow;
use App\Actions\QuestionBlueprints\RunBlueprintAiFill;
use App\Enums\BlueprintErrorCode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PDOException;
use Throwable;

class FillQuestionBlueprintJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 270;

    public bool $failOnTimeout = false;

    public int $uniqueFor = 900;

    public function __construct(
        public int $blueprintId,
        public string $workflowToken,
        public string $stepExecutionToken,
    ) {
        $this->timeout = (int) config('question_blueprint.job_timeout_seconds', 270);
        $this->onConnection((string) config('question_blueprint.queue_connection', 'database-generation'));
        $this->onQueue((string) config('question_blueprint.queue', 'material-intelligence'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'question-blueprint-fill:'.$this->blueprintId.':'.$this->stepExecutionToken;
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
            (new WithoutOverlapping('question-blueprint:'.$this->blueprintId))
                ->releaseAfter(60)
                ->expireAfter(330),
        ];
    }

    public function handle(RunBlueprintAiFill $action): void
    {
        $action->handle($this->blueprintId, $this->workflowToken, $this->stepExecutionToken);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            return;
        }

        app(FailBlueprintAttemptAndWorkflow::class)->handle(
            $this->blueprintId,
            $this->workflowToken,
            $this->stepExecutionToken,
            null,
            BlueprintErrorCode::ProviderFailed,
        );
    }
}
