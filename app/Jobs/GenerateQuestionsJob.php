<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Generations\FinalizeGenerationFailure;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\GenerationErrorCode;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class GenerateQuestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 270;

    public int $uniqueFor = 1800;

    public bool $failOnTimeout = false;

    public function __construct(
        public int $generationId,
        public string $executionToken = '',
    ) {
        if ($this->executionToken === '') {
            $this->executionToken = (string) Str::uuid();
        }

        $this->onConnection((string) config('generation.queue_connection', 'database-generation'));
        $this->onQueue((string) config('generation.queue', 'question-generation'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('question-generation:'.$this->generationId))
                ->releaseAfter(60)
                ->expireAfter(330),
        ];
    }

    public function handle(RunQuestionGeneration $action): void
    {
        $action->handle($this->generationId, $this->executionToken);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(FinalizeGenerationFailure::class)->handle(
                $this->generationId,
                $this->executionToken,
                GenerationErrorCode::JobFailed,
            );
        } catch (StaleGenerationExecutionException) {
            return;
        }
    }
}
