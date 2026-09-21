<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\QuestionBlueprints\ProcessQuestionBlueprintImportExtraction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ExtractQuestionBlueprintImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 900;

    public function __construct(public int $importId)
    {
        $this->onQueue('material-extraction');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('blueprint-import-extraction:'.$this->importId))
                ->releaseAfter(120)
                ->expireAfter(180),
        ];
    }

    public function handle(ProcessQuestionBlueprintImportExtraction $processor): void
    {
        $processor->handle($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessQuestionBlueprintImportExtraction::class)->markFailedIfProcessing($this->importId);
    }
}
