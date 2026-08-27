<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Materials\ProcessMaterialExtraction;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ExtractMaterialContent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 900;

    public function __construct(public int $materialId)
    {
        $this->onQueue('material-extraction');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->materialId;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('material-extraction:'.$this->materialId))
                ->releaseAfter(120)
                ->expireAfter(180),
        ];
    }

    public function handle(ProcessMaterialExtraction $action): void
    {
        try {
            $action->handle($this->materialId);
        } catch (UnrecoverableMaterialExtractionException $exception) {
            $action->markFailedIfProcessing($this->materialId);
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessMaterialExtraction::class)->markFailedIfProcessing($this->materialId);
    }
}
