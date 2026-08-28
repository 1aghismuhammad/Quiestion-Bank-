<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

final class CalendarMonths
{
    public static function addNoOverflow(Carbon $anchor, int $months): Carbon
    {
        return $anchor->copy()->addMonthsNoOverflow($months);
    }
}
