<?php

declare(strict_types=1);

namespace App\Data\Generations;

interface ValidatedQuestionSet
{
    public function count(): int;

    /**
     * @return list<string>
     */
    public function questionTexts(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array;

    /**
     * @return list<object>
     */
    public function items(): array;
}
