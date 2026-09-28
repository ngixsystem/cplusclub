<?php

namespace Tests\Unit;

use App\Domain\Tickets\TicketStatus as S;
use PHPUnit\Framework\TestCase;

class TicketStatusTest extends TestCase
{
    public function test_cannot_skip_work_and_can_reopen(): void
    {
        self::assertFalse(S::New->canMoveTo(S::Closed));
        self::assertFalse(S::Working->canMoveTo(S::Closed));
        self::assertTrue(S::Closed->canMoveTo(S::Accepted));
        self::assertTrue(S::Working->canMoveTo(S::Approval));
        self::assertTrue(S::Approval->canMoveTo(S::Working));
    }
}
