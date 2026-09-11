<?php

declare(strict_types=1);

namespace App\Enums;

enum CognitiveLevel: string
{
    case Remember = 'remember';
    case Understand = 'understand';
    case Apply = 'apply';
    case Analyze = 'analyze';
    case Evaluate = 'evaluate';
    case Create = 'create';

    public function label(): string
    {
        return match ($this) {
            self::Remember => 'Mengingat',
            self::Understand => 'Memahami',
            self::Apply => 'Menerapkan',
            self::Analyze => 'Menganalisis',
            self::Evaluate => 'Mengevaluasi',
            self::Create => 'Mencipta',
        };
    }
}
