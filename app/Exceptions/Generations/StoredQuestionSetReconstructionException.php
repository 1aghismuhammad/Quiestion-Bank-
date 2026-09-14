<?php

declare(strict_types=1);

namespace App\Exceptions\Generations;

use App\Enums\GenerationErrorCode;
use InvalidArgumentException;

class StoredQuestionSetReconstructionException extends InvalidArgumentException
{
    public function errorCode(): GenerationErrorCode
    {
        return GenerationErrorCode::MalformedOutput;
    }
}
