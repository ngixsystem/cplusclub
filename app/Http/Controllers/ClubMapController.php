<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClubAccess;
use App\Domain\ClubMap\ClubMapStatus;
use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class ClubMapController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->active, 403);
        $clubs = ClubAccess::filter(Club::query(), $request->user(), 'id')->whereNotNull('map_layout')
            ->orderBy('name')->get(['id', 'name']);
        $selected = $clubs->firstWhere('id', (int) $request->query('club')) ?? $clubs->first();

        return Inertia::render('ClubMap', [
            'clubs' => $clubs,
            'club' => $selected?->only('id', 'name'),
            'layout' => $selected ? Club::find($selected->id)?->map_layout : null,
        ]);
    }

    public function status(Club $club, ClubMapStatus $status)
    {
        Gate::authorize('view', $club);
        abort_unless($club->map_layout !== null, 404);
        return response()->json($status->snapshot($club))->header('Cache-Control', 'no-store');
    }
}
