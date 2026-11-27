<?php

namespace App\Domain\Tickets;

use App\Models\{Ticket, User};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;

/**
 * Auto-created «link dropped to 100 Mbit/s» ticket closed in one step: the engineer only ticks
 * which ends of the cable were re-crimped. History and timestamps stay as in a normal close.
 */
final class QuickLinkFix
{
    public const OPEN = ['new', 'accepted', 'working', 'parts', 'resolved'];

    public function execute(User $actor, int $id, int $version, bool $pcSide, bool $switchSide, ?string $comment = null): Ticket
    {
        if (! $pcSide && ! $switchSide) throw ValidationException::withMessages(['pc_side' => 'Отметьте, с какой стороны обжат кабель.']);
        return DB::transaction(function () use ($actor, $id, $version, $pcSide, $switchSide, $comment) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $ticket);
            abort_if($ticket->version !== $version, 409, 'Заявка изменена другим пользователем. Обновите страницу.');
            if ($ticket->source !== 'link') throw ValidationException::withMessages(['status' => 'Быстрое закрытие только для заявок по сетевому линку.']);
            if (! in_array($ticket->status, self::OPEN, true)) throw ValidationException::withMessages(['status' => 'Заявку в этом статусе так закрыть нельзя.']);
            if (DB::table('ticket_proposals')->where('ticket_id', $id)->exists()) throw ValidationException::withMessages(['status' => 'По заявке есть согласование — закройте её обычным способом.']);

            $done = array_values(array_filter([$pcSide ? 'Обжат со стороны ПК' : null, $switchSide ? 'Обжат со стороны сетевого коммутатора' : null]));
            $linkOk = $ticket->equipment_id && DB::table('alerts')->where('equipment_id', $ticket->equipment_id)->where('metric', 'link_speed')->where('state', '!=', 'active')->exists();
            $from = $ticket->status;
            $now = now();
            $ticket->work_result = implode("\n", $done);
            $ticket->cause = 'Плохой контакт в разъёме RJ-45 — линк падал до 100 Мбит/с';
            $ticket->solution = 'Кабель переобжат: '.mb_strtolower(implode(', ', array_map(fn ($d) => str_replace('Обжат со стороны ', '', $d), $done))).'.';
            $ticket->verification_result = $linkOk ? 'Мониторинг: скорость линка в норме.' : 'Скорость подтвердит мониторинг при следующей загрузке ПК.';
            $ticket->assignee_id ??= $actor->id;
            $ticket->first_responded_at ??= $now;
            $ticket->resolved_at ??= $now;
            $ticket->closed_at = $now;
            $ticket->status = TicketStatus::Closed->value;
            $ticket->version++;
            $ticket->save();
            $ticket->events()->create(['actor_id' => $actor->id, 'from_status' => $from, 'to_status' => 'closed',
                'comment' => trim(implode(', ', $done).'. '.($comment ?? ''))]);
            \App\Domain\Telegram\TicketNotices::status($ticket, $from);
            return $ticket;
        });
    }
}
