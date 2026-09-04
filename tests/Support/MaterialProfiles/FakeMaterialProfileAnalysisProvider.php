<?php

declare(strict_types=1);

namespace Tests\Support\MaterialProfiles;

use App\Contracts\AI\MaterialProfileAnalysisProvider;
use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Data\MaterialProfiles\ProfileMapResult;
use App\Data\MaterialProfiles\ProfileProviderAttemptMetadata;
use App\Data\MaterialProfiles\ProfileProviderIdentity;
use App\Data\MaterialProfiles\ProfileReduceRequest;
use App\Data\MaterialProfiles\ProfileReduceResult;
use App\Data\MaterialProfiles\SuggestedProfileCandidate;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileStepPurpose;
use Closure;
use Throwable;

/**
 * Provider double that records every request so tests can prove exactly what
 * was sent to the boundary.
 */
class FakeMaterialProfileAnalysisProvider implements MaterialProfileAnalysisProvider
{
    public const PROVIDER_NAME = 'fake_material_profile';

    /** @var list<ProfileMapRequest> */
    public array $mapRequests = [];

    /** @var list<ProfileReduceRequest> */
    public array $reduceRequests = [];

    public int $mapCalls = 0;

    public int $reduceCalls = 0;

    /** @var Closure(ProfileMapRequest, int): (ProfileMapResult|Throwable)|null */
    public ?Closure $mapUsing = null;

    /** @var Closure(ProfileReduceRequest, int): (ProfileReduceResult|Throwable)|null */
    public ?Closure $reduceUsing = null;

    public function identity(): ProfileProviderIdentity
    {
        return new ProfileProviderIdentity(self::PROVIDER_NAME);
    }

    public function analyzeChunk(ProfileMapRequest $request): ProfileMapResult
    {
        $this->mapCalls++;
        $this->mapRequests[] = $request;

        if ($this->mapUsing === null) {
            return self::defaultMapResult($request);
        }

        $outcome = ($this->mapUsing)($request, $this->mapCalls);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    public function reduceProfile(ProfileReduceRequest $request): ProfileReduceResult
    {
        $this->reduceCalls++;
        $this->reduceRequests[] = $request;

        if ($this->reduceUsing === null) {
            return self::defaultReduceResult();
        }

        $outcome = ($this->reduceUsing)($request, $this->reduceCalls);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * One valid topic observation whose evidence quotes the start of the core.
     */
    public static function defaultMapResult(ProfileMapRequest $request, int $length = 12): ProfileMapResult
    {
        $end = min($length, $request->coreLength());
        $excerpt = mb_substr($request->coreText, 0, $end, 'UTF-8');

        return self::mapResult($request, [
            new ExtractedProfileCandidate(
                MaterialProfileElementKind::TOPIC->value,
                'Topik bagian '.$request->chunkIndex,
                $excerpt,
                0,
                $end,
            ),
        ]);
    }

    /**
     * @param  list<ExtractedProfileCandidate>  $candidates
     */
    public static function mapResult(ProfileMapRequest $request, array $candidates): ProfileMapResult
    {
        return new ProfileMapResult($candidates, self::metadata(
            MaterialProfileStepPurpose::MAP,
            $request->model,
            $request->promptVersion,
        ));
    }

    public static function defaultReduceResult(): ProfileReduceResult
    {
        return self::reduceResult([
            new SuggestedProfileCandidate(MaterialProfileElementKind::TOPIC->value, 'Cakupan materi keseluruhan'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::OBJECTIVE->value, 'Peserta mampu menjelaskan konsep utama'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::INDICATOR->value, 'Peserta menyebutkan tiga contoh penerapan'),
        ]);
    }

    /**
     * @param  list<SuggestedProfileCandidate>  $candidates
     */
    public static function reduceResult(array $candidates): ProfileReduceResult
    {
        return new ProfileReduceResult($candidates, self::metadata(
            MaterialProfileStepPurpose::REDUCE,
            (string) config('material_profile.primary_model'),
            (string) config('material_profile.reduce_prompt_version'),
        ));
    }

    public static function metadata(
        MaterialProfileStepPurpose $purpose,
        string $model,
        string $promptVersion,
    ): ProfileProviderAttemptMetadata {
        return new ProfileProviderAttemptMetadata(
            provider: self::PROVIDER_NAME,
            model: $model,
            promptVersion: $promptVersion,
            purpose: $purpose,
            inputTokens: 120,
            outputTokens: 45,
            totalTokens: 165,
            latencyMs: 7,
        );
    }
}
