<?php

declare(strict_types=1);

namespace Tests\Unit\Materials\Profile;

use App\Support\MaterialProfiles\MaterialProfileBudgets;
use Tests\TestCase;

class MaterialProfileBudgetTest extends TestCase
{
    public function test_default_map_output_fits_the_reduce_summary_budget(): void
    {
        $this->assertSame(10, (int) config('material_profile.max_map_candidates'));
        $this->assertSame(20, (int) config('material_profile.max_chunks'));
        $this->assertSame(200, (int) config('material_profile.max_reduce_summaries'));
        $this->assertTrue(MaterialProfileBudgets::configurationAllowsLosslessReduce());
        $this->assertSame(200, MaterialProfileBudgets::extractedElementBudget());
        $this->assertLessThanOrEqual(
            MaterialProfileBudgets::maxReduceSummaries(),
            MaterialProfileBudgets::maxMapCandidates() * MaterialProfileBudgets::maxChunks(),
        );
    }
}
