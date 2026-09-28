<?php

namespace App\Domain\Inspections;

use Carbon\CarbonImmutable;

final class InspectionCalendar
{
    /** Calendar date: clamp December 31 to February 28 (29 in a leap year). */
    public static function next(string $completedDate): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $completedDate)
            ->addMonthsNoOverflow(2)->format('Y-m-d');
    }
}
