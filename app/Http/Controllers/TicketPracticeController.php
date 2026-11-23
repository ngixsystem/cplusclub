<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\TicketPractice;
use App\Models\TrainingTicketRun;
use Illuminate\Http\Request;
use Inertia\{Inertia, Response};

/** Ticket practice: sandbox incidents handled through the real ticket workflow (engineers, lead, owner). */
final class TicketPracticeController extends Controller
{
    public function start(string $scenario, Request $request, TicketPractice $practice)
    {
        abort_unless($practice->allows($request->user()), 403);
        $mode = $request->validate(['mode' => 'required|in:demo,exam'])['mode'];
        $run = $practice->start($request->user(), $scenario, $mode);

        return redirect()->route('training.practice', $run);
    }

    public function show(TrainingTicketRun $run, Request $request, TicketPractice $practice): Response
    {
        $this->own($run, $request, $practice);

        return Inertia::render('TicketPractice', $practice->present($run));
    }

    public function act(TrainingTicketRun $run, Request $request, TicketPractice $practice)
    {
        $this->own($run, $request, $practice);
        $type = $request->validate(['type' => 'required|in:create,transition,action,proposal,worklog'])['type'];
        $practice->act($run, $type, $request->except('type'));

        return redirect()->route('training.practice', $run);
    }

    private function own(TrainingTicketRun $run, Request $request, TicketPractice $practice): void
    {
        abort_unless($practice->allows($request->user()), 403);
        abort_unless($run->user_id === $request->user()->id, 404);
    }
}
