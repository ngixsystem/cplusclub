<?php

namespace App\Http\Controllers;

use App\Domain\Tickets\TicketApproval;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TicketApprovalController extends Controller
{
    public function propose(Request $r, Ticket $ticket, TicketApproval $service)
    {
        Gate::authorize('update', $ticket);
        $d = $r->validate(['version' => 'required|integer|min:1', 'description' => 'required|string|max:10000',
            'amount_minor' => 'required|integer|min:0|max:100000000000', 'currency' => 'required|string|regex:/^[A-Z]{3}$/',
            'expected_duration' => 'required|string|max:255']);
        $service->propose($r->user(), $ticket->id, $d['version'], $d);
        return back()->with('success', 'Предложение отправлено владельцу клуба.');
    }

    public function decide(Request $r, Ticket $ticket, TicketApproval $service)
    {
        Gate::authorize('decide', $ticket);
        $d = $r->validate(['version' => 'required|integer|min:1', 'proposal_id' => 'required|integer|min:1',
            'decision' => ['required', Rule::in(['approve', 'reject'])], 'comment' => 'nullable|string|max:5000']);
        $service->decide($r->user(), $ticket->id, $d['version'], $d['proposal_id'], $d['decision'], $d['comment'] ?? '');
        return back()->with('success', 'Решение сохранено.');
    }

    public function complete(Request $r, Ticket $ticket, TicketApproval $service)
    {
        Gate::authorize('decide', $ticket);
        $d = $r->validate(['version' => 'required|integer|min:1',
            'decision' => ['required', Rule::in(['confirm', 'rework'])], 'comment' => 'nullable|string|max:5000']);
        $service->complete($r->user(), $ticket->id, $d['version'], $d['decision'], $d['comment'] ?? '');
        return back()->with('success', 'Решение по результату сохранено.');
    }
}
