<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\SuggestedProfileCandidate;
use App\Data\MaterialProfiles\ValidatedProfileElement;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\MaterialProfiles\MaterialProfileCandidateValidationException;

/**
 * Server-side validation for reduce output. Suggested elements carry no source
 * chunk and no evidence, and the server owns origin and ordering.
 */
class ValidateProfileReduceCandidates
{
    use NormalizesProfileCandidateText;

    /**
     * @param  list<SuggestedProfileCandidate>  $candidates
     * @return list<ValidatedProfileElement>
     */
    public function handle(array $candidates): array
    {
        $maxCandidates = max(1, (int) config('material_profile.max_suggested_elements', 40));

        if (count($candidates) > $maxCandidates) {
            throw new MaterialProfileCandidateValidationException('Suggested candidate count exceeds the limit.');
        }

        $seen = [];
        $validated = [];

        foreach ($candidates as $candidate) {
            $kind = $this->supportedCandidateKind($candidate->kind);
            $text = $this->normalizedCandidateText($candidate->text);
            $key = $this->normalizedDedupeKey($kind, $text);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $validated[] = new ValidatedProfileElement(
                kind: $kind,
                text: $text,
                origin: MaterialProfileElementOrigin::SUGGESTED,
            );
        }

        $this->assertRequiredKinds($validated);

        return $validated;
    }

    /**
     * @param  list<ValidatedProfileElement>  $elements
     */
    private function assertRequiredKinds(array $elements): void
    {
        $required = [
            MaterialProfileElementKind::TOPIC,
            MaterialProfileElementKind::OBJECTIVE,
            MaterialProfileElementKind::INDICATOR,
        ];

        foreach ($required as $kind) {
            $present = false;

            foreach ($elements as $element) {
                if ($element->kind === $kind) {
                    $present = true;
                    break;
                }
            }

            if (! $present) {
                throw new MaterialProfileCandidateValidationException(
                    'Suggested elements are missing a required kind: '.$kind->value.'.',
                );
            }
        }
    }
}
