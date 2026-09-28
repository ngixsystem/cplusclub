<?php

namespace App\Domain\Tickets;

use App\Models\{Ticket, User};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;

final class TransitionTicket
{
    public function execute(User $actor, int $id, TicketStatus $next, int $expectedVersion, array $input): Ticket
    {
        return DB::transaction(function () use ($actor, $id, $next, $expectedVersion, $input) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $ticket);
            abort_if($ticket->version !== $expectedVersion, 409, 'Заявка изменена другим пользователем. Обновите страницу.');
            $previous = TicketStatus::from($ticket->status);
            if (! $previous->canMoveTo($next)) {
                throw ValidationException::withMessages(['status' => 'Этот переход статуса не разрешён.']);
            }
            foreach (['work_result', 'verification_result', 'cause', 'solution'] as $field) {
                if (array_key_exists($field, $input)) {
                    $ticket->$field = trim($input[$field] ?? '');
                }
            }
            if (in_array($next, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
                foreach (['work_result', 'verification_result', 'cause', 'solution'] as $field) {
                    if (! filled($ticket->$field)) {
                        throw ValidationException::withMessages([$field => 'Укажите результат, проверку, причину и решение.']);
                    }
                }
            }
            $ticket->status = $next->value;
            $ticket->version++;
            if ($next === TicketStatus::Accepted && ! $ticket->first_responded_at) $ticket->first_responded_at = now();
            if ($next === TicketStatus::Resolved) $ticket->resolved_at = now();
            if ($next === TicketStatus::Closed) $ticket->closed_at = now();
            if ($previous === TicketStatus::Closed || ($previous === TicketStatus::Resolved && $next === TicketStatus::Working)) {
                $ticket->resolved_at = null;
                $ticket->closed_at = null;
            }
            $ticket->save();
            $ticket->events()->create([
                'actor_id' => $actor->id, 'from_status' => $previous->value,
                'to_status' => $next->value, 'comment' => $input['comment'] ?? null,
            ]);
            return $ticket;
        });
    }
}
