<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentType: string
{
    case FORMATIVE = 'formative';
    case SUMMATIVE = 'summative';
    case DIAGNOSTIC = 'diagnostic';
}
