<?php

declare(strict_types=1);

namespace App\Support\Generations;

use App\Models\AiGenerationRun;

final class GenerationRunShuffle
{
    public const VERSION = 'generation-run-shuffle-v1';

    public function seedMaterial(AiGenerationRun $run): string
    {
        return self::VERSION."\0".(string) $run->generation_run_id."\0".(string) $run->request_fingerprint;
    }

    public function rank(string $seedMaterial, string $domain, string ...$parts): string
    {
        return hash('sha256', $seedMaterial."\0".$domain."\0".implode("\0", $parts));
    }
}
