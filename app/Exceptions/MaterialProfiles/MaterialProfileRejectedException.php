<?php

declare(strict_types=1);

namespace App\Exceptions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use RuntimeException;

class MaterialProfileRejectedException extends RuntimeException
{
    public function __construct(
        public readonly MaterialProfileErrorCode $errorCode,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->userMessage());
    }
}
