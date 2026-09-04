<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\MaterialProfiles\FailMaterialProfileWorkflowForStep;
use App\Actions\MaterialProfiles\RunMaterialProfileReduceStep;
use App\Enums\MaterialProfileErrorCode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Consolidates validated extracted observations into suggested profile elements.
 *
 * Receives the same unchanged Version workflow token as every map Step, plus its
 * own distinct Step execution token.
 */
class ReduceMaterialProfileJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 270;

    public bool $failOnTimeout = false;

    public int $uniqueFor = 900;

    public function __construct(
        public int $profileVersionId,
        public int $profileStepId,
        public string $workflowToken,
        public string $stepExecutionToken,
    ) {
        $this->timeout = (int) config('material_profile.job_timeout_seconds', 270);
        $this->onConnection((string) config('material_profile.queue_connection', 'database-generation'));
        $this->onQueue((string) config('material_profile.queue', 'material-intelligence'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'material-profile-step:'.$this->profileStepId.':'.$this->stepExecutionToken;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = config('material_profile.backoff_seconds', [5, 15]);

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
            (new WithoutOverlapping('material-profile-version:'.$this->profileVersionId))
                ->releaseAfter(60)
                ->expireAfter(330),
        ];
    }

    public function handle(RunMaterialProfileReduceStep $action): void
    {
        $action->handle(
            $this->profileVersionId,
            $this->profileStepId,
            $this->workflowToken,
            $this->stepExecutionToken,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(FailMaterialProfileWorkflowForStep::class)->handle(
            $this->profileVersionId,
            $this->profileStepId,
            $this->workflowToken,
            $this->stepExecutionToken,
            MaterialProfileErrorCode::ProviderFailed,
        );
    }
}
