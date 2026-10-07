<?php

namespace App\Domain\Tickets;

use App\Models\{Ticket, User};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;

final class TicketApproval
{
    private function locked(User $actor, int $id, int $version, string $ability): Ticket
    {
        $ticket = Ticket::lockForUpdate()->findOrFail($id);
        Gate::forUser($actor)->authorize($ability, $ticket);
        abort_if($ticket->version !== $version, 409, 'Заявка изменена. Обновите страницу.');
        return $ticket;
    }

    private function save(Ticket $ticket, User $actor, string $status, string $comment): Ticket
    {
        $previous = $ticket->status;
        $ticket->status = $status;
        $ticket->version++;
        if ($status === 'closed') $ticket->closed_at = now();
        if ($status === 'working') { $ticket->resolved_at = null; $ticket->closed_at = null; }
        $ticket->save();
        $ticket->events()->create(['actor_id' => $actor->id, 'from_status' => $previous,
            'to_status' => $status, 'comment' => $comment]);
        return $ticket;
    }

    public function propose(User $actor, int $id, int $version, array $data): Ticket
    {
        return DB::transaction(function () use ($actor, $id, $version, $data) {
            $ticket = $this->locked($actor, $id, $version, 'update');
            abort_unless(in_array($ticket->status, ['working', 'approval'], true), 422, 'Предложение доступно в работе или на согласовании.');
            // Revisions append a new quote; accepted/rejected terms are never overwritten.
            DB::table('ticket_proposals')->where('ticket_id', $id)->where('status', 'pending')->update(['status' => 'superseded']);
            $proposal = DB::table('ticket_proposals')->insertGetId([
                'ticket_id' => $id, 'author_id' => $actor->id, 'description' => $data['description'],
                'amount_minor' => $data['amount_minor'], 'currency' => $data['currency'],
                'expected_duration' => $data['expected_duration'], 'created_at' => now(),
            ]);
            return $this->save($ticket, $actor, 'approval', 'Отправлено предложение #'.$proposal.' на согласование.');
        });
    }

    public function decide(User $actor, int $id, int $version, int $proposalId, string $decision, string $comment): Ticket
    {
        return DB::transaction(function () use ($actor, $id, $version, $proposalId, $decision, $comment) {
            $ticket = $this->locked($actor, $id, $version, 'decide');
            $proposal = DB::table('ticket_proposals')->where('ticket_id', $id)->orderByDesc('id')->first();
            abort_unless($ticket->status === 'approval' && $proposal && $proposal->id === $proposalId && $proposal->status === 'pending', 422, 'Предложение уже изменено или рассмотрено.');
            abort_unless(in_array($decision, ['approve', 'reject'], true), 422);
            if ($decision === 'reject' && trim($comment) === '') throw ValidationException::withMessages(['comment' => 'Укажите причину отказа.']);
            DB::table('ticket_proposals')->where('id', $proposalId)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment,
            ]);
            return $this->save($ticket, $actor, $decision === 'approve' ? 'working' : 'approval',
                ($decision === 'approve' ? 'Согласовано' : 'Отклонено').' предложение #'.$proposalId.'. '.$comment);
        });
    }

    public function complete(User $actor, int $id, int $version, string $decision, string $comment): Ticket
    {
        return DB::transaction(function () use ($actor, $id, $version, $decision, $comment) {
            $ticket = $this->locked($actor, $id, $version, 'decide');
            abort_unless($ticket->status === 'resolved' && in_array($decision, ['confirm', 'rework'], true), 422);
            if ($decision === 'rework' && trim($comment) === '') throw ValidationException::withMessages(['comment' => 'Укажите, что необходимо доработать.']);
            if ($decision === 'confirm') foreach (['work_result', 'verification_result', 'cause', 'solution'] as $field) {
                if (!filled($ticket->$field)) throw ValidationException::withMessages(['comment' => 'Специалист должен заполнить результаты работы.']);
            }
            return $this->save($ticket, $actor, $decision === 'confirm' ? 'closed' : 'working',
                ($decision === 'confirm' ? 'Владелец клуба подтвердил результат. ' : 'Возвращено на доработку. ').$comment);
        });
    }
}
