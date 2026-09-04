<?php

declare(strict_types=1);

namespace Tests\Support\MaterialProfiles;

use App\Actions\MaterialProfiles\RunMaterialProfileMapStep;
use App\Actions\MaterialProfiles\RunMaterialProfileReduceStep;
use App\Actions\MaterialProfiles\StartMaterialProfileAnalysis;
use App\Contracts\AI\MaterialProfileAnalysisProvider;
use App\Data\MaterialProfiles\MaterialProfileStartResult;
use App\Enums\MaterialProfileStepPurpose;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\Material;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Throwable;

trait RunsMaterialProfileWorkflows
{
    protected function fakeProfileProvider(): FakeMaterialProfileAnalysisProvider
    {
        $fake = new FakeMaterialProfileAnalysisProvider;
        $this->app->instance(MaterialProfileAnalysisProvider::class, $fake);

        return $fake;
    }

    protected function startProfileAnalysis(
        User $actor,
        Material $material,
        bool $forceRegenerate = false,
    ): MaterialProfileStartResult {
        return $this->app->make(StartMaterialProfileAnalysis::class)
            ->handle($actor, $material, $forceRegenerate);
    }

    /**
     * Start analysis and run the complete map/reduce chain to a ready Version.
     */
    protected function completeProfileAnalysis(
        User $actor,
        Material $material,
        bool $forceRegenerate = false,
    ): MaterialProfileVersion {
        $result = $this->startProfileAnalysis($actor, $material, $forceRegenerate);
        $this->drainProfileJobs();

        return $result->version->fresh();
    }

    /**
     * Run every queued profile Job exactly once, in push order, re-scanning the
     * fake queue after each run so newly dispatched Steps are picked up.
     */
    protected function drainProfileJobs(int $max = 40): int
    {
        $ran = 0;
        $done = [];

        while ($ran < $max) {
            $job = $this->nextPendingProfileJob($done);

            if ($job === null) {
                break;
            }

            $done[$this->profileJobKey($job)] = true;
            $this->runProfileJob($job);
            $ran++;
        }

        return $ran;
    }

    protected function runProfileJob(AnalyzeMaterialProfileMapJob|ReduceMaterialProfileJob $job): void
    {
        if ($job instanceof AnalyzeMaterialProfileMapJob) {
            $job->handle($this->app->make(RunMaterialProfileMapStep::class));

            return;
        }

        $job->handle($this->app->make(RunMaterialProfileReduceStep::class));
    }

    /**
     * Run a Job and swallow the retry signal, mimicking a queue worker that
     * releases the delivery back for another attempt.
     */
    protected function runProfileJobExpectingRetry(
        AnalyzeMaterialProfileMapJob|ReduceMaterialProfileJob $job,
    ): ?Throwable {
        try {
            $this->runProfileJob($job);
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    /**
     * @param  array<string, true>  $done
     */
    protected function nextPendingProfileJob(array $done): AnalyzeMaterialProfileMapJob|ReduceMaterialProfileJob|null
    {
        foreach ([AnalyzeMaterialProfileMapJob::class, ReduceMaterialProfileJob::class] as $class) {
            foreach (Queue::pushed($class) as $job) {
                if (! isset($done[$this->profileJobKey($job)])) {
                    return $job;
                }
            }
        }

        return null;
    }

    protected function profileJobKey(AnalyzeMaterialProfileMapJob|ReduceMaterialProfileJob $job): string
    {
        return $job::class.':'.$job->profileStepId.':'.$job->stepExecutionToken;
    }

    /**
     * @return list<AnalyzeMaterialProfileMapJob>
     */
    protected function pushedMapJobs(): array
    {
        return Queue::pushed(AnalyzeMaterialProfileMapJob::class)->values()->all();
    }

    /**
     * @return list<ReduceMaterialProfileJob>
     */
    protected function pushedReduceJobs(): array
    {
        return Queue::pushed(ReduceMaterialProfileJob::class)->values()->all();
    }

    protected function mapStep(MaterialProfileVersion $version, int $stepIndex): MaterialProfileStep
    {
        return MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('purpose', MaterialProfileStepPurpose::MAP)
            ->where('step_index', $stepIndex)
            ->firstOrFail();
    }

    protected function reduceStepOf(MaterialProfileVersion $version): MaterialProfileStep
    {
        return MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('purpose', MaterialProfileStepPurpose::REDUCE)
            ->firstOrFail();
    }

    /**
     * Content that splits into exactly the requested number of chunks.
     *
     * Each paragraph is longer than half the core budget, so the splitter can
     * never pack two paragraphs into one canonical core and every paragraph
     * becomes its own chunk.
     */
    protected function multiChunkContent(int $chunks, int $coreMax = 300, int $paragraphChars = 200): string
    {
        config(['material_profile.chunk_core_max_chars' => $coreMax]);

        $paragraphs = [];

        for ($index = 0; $index < $chunks; $index++) {
            $paragraph = 'Bagian '.$index.'. Materi ajar bagian '.$index.'. '.str_repeat('kata ', 60);
            $paragraphs[] = mb_substr($paragraph, 0, $paragraphChars, 'UTF-8');
        }

        return implode("\n\n", $paragraphs);
    }
}
