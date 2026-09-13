<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintMode;
use App\Models\QuestionBlueprintRow;
use Illuminate\Support\Collection;

class AssertBlueprintShape
{
    public function __construct(
        private AssertSimpleBlueprintShape $assertSimple,
        private AssertAdvancedBlueprintShape $assertAdvanced,
    ) {}

    /**
     * @param  Collection<int, QuestionBlueprintRow>|list<array<string, mixed>>  $rows
     */
    public function handle(iterable $rows, BlueprintMode $mode): void
    {
        match ($mode) {
            BlueprintMode::Simple => $this->assertSimple->handle($rows),
            BlueprintMode::Advanced => $this->assertAdvanced->handle($rows),
        };
    }
}
