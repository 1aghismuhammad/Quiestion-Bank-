<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintMode;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;

final readonly class BlueprintFillTypeCounts
{
    /** @var list<string> */
    public const KEYS = [
        QuestionType::MULTIPLE_CHOICE->value,
        QuestionType::TRUE_FALSE->value,
        QuestionType::ESSAY->value,
    ];

    public function __construct(
        public int $multipleChoice,
        public int $trueFalse,
        public int $essay,
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromPersisted(mixed $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        return self::parse($raw);
    }

    public static function fromSingleType(QuestionType $type, int $total): self
    {
        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => new self($total, 0, 0),
            QuestionType::TRUE_FALSE => new self(0, $total, 0),
            QuestionType::ESSAY => new self(0, 0, $total),
        };
    }

    public static function parse(mixed $raw): self
    {
        if (! is_array($raw)) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        foreach (array_keys($raw) as $key) {
            if (! in_array((string) $key, self::KEYS, true)) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }
        }

        $parsed = [];

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $raw)) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            $parsed[$key] = self::requireCount($raw[$key]);
        }

        $counts = new self(
            $parsed[QuestionType::MULTIPLE_CHOICE->value],
            $parsed[QuestionType::TRUE_FALSE->value],
            $parsed[QuestionType::ESSAY->value],
        );

        if ($counts->total() < 1) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return $counts;
    }

    public function total(): int
    {
        return $this->multipleChoice + $this->trueFalse + $this->essay;
    }

    public function countFor(QuestionType $type): int
    {
        return match ($type) {
            QuestionType::MULTIPLE_CHOICE => $this->multipleChoice,
            QuestionType::TRUE_FALSE => $this->trueFalse,
            QuestionType::ESSAY => $this->essay,
        };
    }

    /**
     * @return array{multiple_choice: int, true_false: int, essay: int}
     */
    public function toArray(): array
    {
        return [
            QuestionType::MULTIPLE_CHOICE->value => $this->multipleChoice,
            QuestionType::TRUE_FALSE->value => $this->trueFalse,
            QuestionType::ESSAY->value => $this->essay,
        ];
    }

    public function nonZeroTypeCount(): int
    {
        $count = 0;

        foreach ([$this->multipleChoice, $this->trueFalse, $this->essay] as $value) {
            if ($value > 0) {
                $count++;
            }
        }

        return $count;
    }

    public function assertCompatibleWith(BlueprintMode $mode): void
    {
        $total = $this->total();
        $nonZero = $this->nonZeroTypeCount();

        if ($mode === BlueprintMode::Simple) {
            $max = (int) config('question_blueprint.max_total_requested', 10);

            if ($nonZero !== 1 || $total < 1 || $total > $max) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            return;
        }

        $max = (int) config('question_blueprint.max_advanced_total_requested', 30);

        if ($nonZero < 1 || $total < 1 || $total > $max) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }
    }

    public function matchesRows(array $rows): bool
    {
        $actual = [
            QuestionType::MULTIPLE_CHOICE->value => 0,
            QuestionType::TRUE_FALSE->value => 0,
            QuestionType::ESSAY->value => 0,
        ];

        foreach ($rows as $row) {
            $type = $row['question_type'] ?? null;
            $resolved = $type instanceof QuestionType
                ? $type
                : QuestionType::tryFrom((string) $type);

            if ($resolved === null) {
                return false;
            }

            $actual[$resolved->value] += (int) ($row['requested_count'] ?? 0);
        }

        return $actual === $this->toArray();
    }

    private static function requireCount(mixed $value): int
    {
        if (is_bool($value) || is_float($value) || $value === null) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        if (is_int($value)) {
            $count = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $count = (int) $value;
        } else {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        if ($count < 0 || $count > 30) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return $count;
    }
}
