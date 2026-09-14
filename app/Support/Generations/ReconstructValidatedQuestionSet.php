<?php

declare(strict_types=1);

namespace App\Support\Generations;

use App\Data\Generations\ValidatedEssaySet;
use App\Data\Generations\ValidatedMcqSet;
use App\Data\Generations\ValidatedQuestionSet;
use App\Data\Generations\ValidatedTrueFalseSet;
use App\Enums\QuestionType;
use App\Exceptions\Generations\StoredQuestionSetReconstructionException;

final class ReconstructValidatedQuestionSet
{
    public static function empty(QuestionType $type): ValidatedQuestionSet
    {
        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => new ValidatedMcqSet([]),
            QuestionType::TRUE_FALSE => new ValidatedTrueFalseSet([]),
            QuestionType::ESSAY => new ValidatedEssaySet([]),
        };
    }

    public static function fromStored(QuestionType $type, mixed $payload, ?int $requestedCount = null): ValidatedQuestionSet
    {
        if ($payload === null || $payload === []) {
            $set = self::empty($type);
            self::assertTrueFalseFeasibility($type, $set, $requestedCount);

            return $set;
        }

        if ($type === QuestionType::MULTIPLE_CHOICE) {
            if (! is_array($payload)) {
                return self::empty($type);
            }

            return ValidatedMcqSet::fromStoredJson($payload);
        }

        if (! is_array($payload)) {
            throw new StoredQuestionSetReconstructionException(
                'Stored question payload must be a list.',
            );
        }

        $set = match ($type) {
            QuestionType::TRUE_FALSE => ValidatedTrueFalseSet::fromStoredJson($payload),
            QuestionType::ESSAY => ValidatedEssaySet::fromStoredJson($payload),
            QuestionType::MULTIPLE_CHOICE => self::empty($type),
        };

        self::assertTrueFalseFeasibility($type, $set, $requestedCount);

        return $set;
    }

    /**
     * @param  list<object>  $incoming
     */
    public static function merge(ValidatedQuestionSet $accepted, array $incoming, int $needed): ValidatedQuestionSet
    {
        $items = $accepted->items();

        foreach ($incoming as $item) {
            if (count($items) >= $needed) {
                break;
            }

            $items[] = $item;
        }

        return match (true) {
            $accepted instanceof ValidatedMcqSet => new ValidatedMcqSet($items),
            $accepted instanceof ValidatedTrueFalseSet => new ValidatedTrueFalseSet($items),
            $accepted instanceof ValidatedEssaySet => new ValidatedEssaySet($items),
            default => $accepted,
        };
    }

    private static function assertTrueFalseFeasibility(
        QuestionType $type,
        ValidatedQuestionSet $set,
        ?int $requestedCount,
    ): void {
        if ($type !== QuestionType::TRUE_FALSE || $requestedCount === null || ! $set instanceof ValidatedTrueFalseSet) {
            return;
        }

        if (! TrueFalseDistribution::isFeasible($requestedCount, $set->trueCount(), $set->falseCount())) {
            throw new StoredQuestionSetReconstructionException(
                'Stored True/False distribution is not feasible.',
            );
        }
    }
}
