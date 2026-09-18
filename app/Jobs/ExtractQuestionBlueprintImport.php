<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\QuestionBlueprints\ProcessQuestionBlueprintImportExtraction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractQuestionBlueprintImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 180;
    public function __construct(public int $importId)
    {
        $this->onQueue('material-extraction');
        $this->afterCommit = true;
    }

    public function handle(ProcessQuestionBlueprintImportExtraction $processor): void
    {
        $processor->handle($this->importId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(ProcessQuestionBlueprintImportExtraction::class)->markFailedIfProcessing($this->importId);
    }
}
