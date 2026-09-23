<?php

declare(strict_types=1);

namespace App\Enums;

enum BlueprintImportGroundingCandidateStatus: string
{
    case GROUNDED = 'grounded';
    case PARTIAL = 'partial';
    case UNGROUNDED = 'ungrounded';
    case NOT_APPLICABLE = 'not_applicable';
}
