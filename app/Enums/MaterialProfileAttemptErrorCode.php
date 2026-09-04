<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialProfileAttemptErrorCode: string
{
    case ProviderTimeout = 'provider_timeout';
    case ProviderHttp = 'provider_http';
    case SchemaInvalid = 'schema_invalid';
    case ValidationFailed = 'validation_failed';
}
