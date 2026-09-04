<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use Tests\TestCase;

class MaterialProfileProviderBoundaryTest extends TestCase
{
    public function test_profile_actions_and_jobs_do_not_import_the_concrete_gemini_adapter(): void
    {
        $paths = [
            ...glob(app_path('Actions/MaterialProfiles/*.php')) ?: [],
            app_path('Jobs/AnalyzeMaterialProfileMapJob.php'),
            app_path('Jobs/ReduceMaterialProfileJob.php'),
        ];

        $this->assertNotSame([], $paths);

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);

            $this->assertStringNotContainsString(
                'GeminiMaterialProfileProvider',
                $source,
                basename($path).' must not import the concrete Gemini adapter.',
            );
            $this->assertStringNotContainsString('App\\Services\\AI\\GeminiMaterialProfileProvider', $source);
        }
    }
}
