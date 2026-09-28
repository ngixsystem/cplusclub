<?php

namespace App\Domain\Tickets;

enum TicketStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Working = 'working';
    case Approval = 'approval';
    case Parts = 'parts';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function canMoveTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::New => [self::Accepted],
            self::Accepted => [self::Working],
            self::Working => [self::Approval, self::Parts, self::Resolved],
            self::Approval, self::Parts => [self::Working],
            self::Resolved => [self::Closed, self::Working],
            self::Closed => [self::Accepted],
        }, true);
    }
}
