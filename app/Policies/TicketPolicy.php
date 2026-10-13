<?php

namespace App\Policies;

use App\Domain\Access\ClubAccess;
use App\Models\{Ticket, User};

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->active && (ClubAccess::allows($user, $ticket->club_id)
            || ($user->role === 'specialist' && $ticket->assignee_id === $user->id));
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket) && $user->role !== 'representative'
            && !($ticket->status === 'closed' && $user->role === 'owner');
    }

    public function attach(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)
            && !($ticket->status === 'closed' && in_array($user->role, ['owner','representative'], true));
    }

    public function decide(User $user, Ticket $ticket): bool
    {
        return $user->active && $ticket->status !== 'closed' && $user->role === 'representative'
            && ClubAccess::allows($user, $ticket->club_id);
    }
}
