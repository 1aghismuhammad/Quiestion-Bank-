<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminSubscriptionActionType: string
{
    case GRANT = 'grant';
    case EXTEND = 'extend';
    case CANCEL = 'cancel';
}
