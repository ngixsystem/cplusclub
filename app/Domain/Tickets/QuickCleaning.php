<?php

namespace App\Domain\Tickets;

use App\Models\{Attachment, Equipment, Ticket, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};

/**
 * PC cleaning done on the spot: one step creates the ticket, stores the before/after photos
 * and closes it, keeping the usual history (new → closed) and timestamps.
 */
final class QuickCleaning
{
    public const CATEGORY = 'Чистка ПК';

    /** @param list<UploadedFile> $before @param list<UploadedFile> $after */
    public function execute(User $engineer, int $clubId, int $equipmentId, string $workResult, string $verification, array $before, array $after, ?string $comment = null): Ticket
    {
        $stored = [];
        try {
            foreach (['before' => $before, 'after' => $after] as $stage => $files) {
                foreach ($files as $f) $stored[] = [$stage, $f, $f->store('attachments', 'local')];
            }
            return DB::transaction(function () use ($engineer, $clubId, $equipmentId, $workResult, $verification, $stored, $comment) {
                $pc = Equipment::findOrFail($equipmentId);
                $now = now();
                $ticket = new Ticket([
                    'club_id' => $clubId, 'equipment_id' => $pc->id, 'category' => self::CATEGORY,
                    'description' => 'Плановая чистка ПК '.$pc->name, 'priority' => 'low',
                    'initiator_id' => $engineer->id, 'assignee_id' => $engineer->id,
                ]);
                $ticket->work_result = trim($workResult);
                $ticket->verification_result = trim($verification);
                $ticket->cause = 'Плановая чистка';
                $ticket->solution = $ticket->work_result;
                $ticket->status = TicketStatus::Closed->value;
                $ticket->first_responded_at = $now;
                $ticket->resolved_at = $now;
                $ticket->closed_at = $now;
                $ticket->save();
                foreach ($stored as [$stage, $f, $path]) {
                    $a = new Attachment();
                    $a->club_id = $clubId; $a->uploaded_by = $engineer->id; $a->ticket_id = $ticket->id; $a->stage = $stage;
                    $a->path = $path; $a->name = mb_substr(basename($f->getClientOriginalName()), 0, 255);
                    $a->mime = $f->getMimeType(); $a->size = $f->getSize();
                    $a->save();
                }
                $ticket->events()->create([
                    'actor_id' => $engineer->id, 'from_status' => TicketStatus::New->value, 'to_status' => TicketStatus::Closed->value,
                    'comment' => trim('Чистка выполнена и закрыта на месте. '.($comment ?? '')),
                ]);
                return $ticket;
            });
        } catch (\Throwable $e) {
            foreach ($stored as [, , $path]) Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
