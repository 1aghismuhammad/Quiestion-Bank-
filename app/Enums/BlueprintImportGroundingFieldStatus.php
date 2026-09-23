<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintImportGroundingFieldStatus: string
{
    case GROUNDED = 'grounded';
    case UNRESOLVED = 'unresolved';
    case AMBIGUOUS = 'ambiguous';
    case NOT_APPLICABLE = 'not_applicable';
}
