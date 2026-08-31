<?php

declare(strict_types=1);

namespace Tests\Unit\Generations;

use App\Support\Generations\ProviderAttemptBudget;
use Tests\TestCase;

class ProviderAttemptBudgetTest extends TestCase
{
    public function test_max_hard_caps_at_three_even_when_config_is_four(): void
    {
        config(['generation.max_provider_attempts' => 4]);

        $this->assertSame(3, ProviderAttemptBudget::MAX);
        $this->assertSame(3, ProviderAttemptBudget::max());
    }

    public function test_max_defaults_to_three_when_config_is_absent(): void
    {
        config(['generation.max_provider_attempts' => null]);

        $this->assertSame(3, ProviderAttemptBudget::max());
    }
}
