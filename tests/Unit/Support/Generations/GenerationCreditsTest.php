<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Generations;

use App\Support\Generations\GenerationCredits;
use PHPUnit\Framework\TestCase;

class GenerationCreditsTest extends TestCase
{
    public function test_credit_bands(): void
    {
        $this->assertSame(1, GenerationCredits::required(1));
        $this->assertSame(1, GenerationCredits::required(10));
        $this->assertSame(2, GenerationCredits::required(11));
        $this->assertSame(2, GenerationCredits::required(20));
        $this->assertSame(3, GenerationCredits::required(21));
    }
}
