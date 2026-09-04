<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\MaterialProfileStep;
use Illuminate\Database\Eloquent\Collection;

/**
 * Single canonical definition of workflow ordering: map Steps run in ascending
 * step_index, and reduce runs only after every map is ready.
 */
trait ResolvesNextMaterialProfileStep
{
    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function expectedNextStep(Collection $steps): ?MaterialProfileStep
    {
        foreach ($this->orderedMapSteps($steps) as $map) {
            if ($map->status === MaterialProfileStepStatus::FAILED) {
                return null;
            }

            if ($map->status !== MaterialProfileStepStatus::READY) {
                return $map;
            }
        }

        $reduce = $this->reduceStep($steps);

        if ($reduce !== null && $reduce->status !== MaterialProfileStepStatus::READY) {
            return $reduce;
        }

        return null;
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     * @return Collection<int, MaterialProfileStep>
     */
    private function orderedMapSteps(Collection $steps): Collection
    {
        return $steps
            ->filter(fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::MAP)
            ->sortBy(fn (MaterialProfileStep $step): int => (int) $step->step_index)
            ->values();
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function reduceStep(Collection $steps): ?MaterialProfileStep
    {
        return $steps->first(
            fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::REDUCE,
        );
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function allMapStepsReady(Collection $steps): bool
    {
        $maps = $this->orderedMapSteps($steps);

        return $maps->isNotEmpty() && $maps->every(
            fn (MaterialProfileStep $step): bool => $step->status === MaterialProfileStepStatus::READY,
        );
    }
}
