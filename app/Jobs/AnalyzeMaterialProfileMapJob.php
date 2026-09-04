<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\MaterialProfiles\FailMaterialProfileWorkflowForStep;
use App\Actions\MaterialProfiles\RunMaterialProfileMapStep;
use App\Enums\MaterialProfileErrorCode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Analyses one canonical chunk core.
 *
 * The workflow token and the Step execution token are supplied explicitly and
 * serialized, so an automatic retry reuses the same Step token. The constructor
 * never mints a workflow token. Uniqueness and overlap middleware are
 * defence-in-depth only: committed database state, ownership, hashes, status,
 * lease, and tokens remain authoritative.
 */
class AnalyzeMaterialProfileMapJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(RunMaterialProfileMapStep $action): void
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
        // The token guards make this a no-op for an obsolete delivery, so a late
        // failure cannot fail a newer or already terminal workflow.
        app(FailMaterialProfileWorkflowForStep::class)->handle(
            $this->profileVersionId,
            $this->profileStepId,
            $this->workflowToken,
            $this->stepExecutionToken,
            MaterialProfileErrorCode::ProviderFailed,
        );
    }
}
