<?php

namespace Tests\Unit;

use App\Domain\Inspections\InspectionCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InspectionCalendarTest extends TestCase
{
    public static function dates(): array
    {
        return [['2025-12-31', '2026-02-28'], ['2023-12-31', '2024-02-29'], ['2026-07-31', '2026-09-30'], ['2026-01-15', '2026-03-15']];
    }

    #[DataProvider('dates')]
    public function test_two_calendar_months_clamp_to_month_end(string $date, string $next): void
    {
        self::assertSame($next, InspectionCalendar::next($date));
    }
}
