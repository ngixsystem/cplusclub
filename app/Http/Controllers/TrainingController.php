<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\{Article, Training};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response};

final class TrainingController extends Controller
{
    public function index(Request $request, Training $training): Response
    {
        Gate::authorize('viewAny', Article::class);
        $user = $request->user();

        return Inertia::render('Training', [
            'tracks' => $training->summary($user),
            'team' => Gate::allows('viewTeam', Article::class) ? $training->team($user) : null,
        ]);
    }
}
