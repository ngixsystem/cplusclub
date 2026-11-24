<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\{Article, TrainingAssignments};
use App\Domain\Telegram\PersonalLink;
use App\Models\{TrainingAssignment, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Deadlines for programs (owner, lead) and the employee's own Telegram link for reminders. */
final class TrainingAssignmentsController extends Controller
{
    public function store(Request $request, TrainingAssignments $assignments)
    {
        Gate::authorize('viewTeam', Article::class);
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'track_id' => 'required|string|max:64',
            'due_on' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:500',
        ], [], ['user_id' => 'сотрудник', 'track_id' => 'программа', 'due_on' => 'срок', 'note' => 'комментарий']);
        $member = User::findOrFail($data['user_id']);
        $assignments->assign($request->user(), $member, $data['track_id'], $data['due_on'], $data['note'] ?? null);

        return back()->with('success', 'Программа назначена: '.$member->name.', срок до '.date('d.m.Y', strtotime($data['due_on'])).'.');
    }

    public function destroy(TrainingAssignment $assignment, Request $request, TrainingAssignments $assignments)
    {
        Gate::authorize('viewTeam', Article::class);
        $assignments->remove($request->user(), $assignment);

        return back()->with('success', 'Назначение снято.');
    }

    public function telegramCode(Request $request, PersonalLink $telegram)
    {
        Gate::authorize('viewAny', Article::class);

        return response()->json($telegram->issue($request->user()));
    }

    public function telegramUnlink(Request $request, PersonalLink $telegram)
    {
        Gate::authorize('viewAny', Article::class);
        $telegram->unlink($request->user());

        return back()->with('success', 'Напоминания в Telegram отключены.');
    }
}
