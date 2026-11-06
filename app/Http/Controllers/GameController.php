<?php

namespace App\Http\Controllers;

use App\Domain\Games\GameUsage;
use Illuminate\Http\Request;

/** Games on the main dashboard: what runs now and what was played most. */
class GameController extends Controller
{
    private function club(Request $r)
    {
        abort_unless($r->user()->active, 403);
        $d = $r->validate(['club_id' => 'required|integer|min:1']);
        $club = GameUsage::clubs($r->user())->firstWhere('id', $d['club_id']);
        abort_unless($club, 403);
        return \App\Models\Club::findOrFail($club->id);
    }

    public function live(Request $r, GameUsage $games)
    {
        return response()->json($games->live($this->club($r)));
    }

    public function stats(Request $r, GameUsage $games)
    {
        $club = $this->club($r);
        $d = $r->validate(['period' => 'nullable|in:'.implode(',', GameUsage::PERIODS), 'trend' => 'nullable|in:days,weeks']);
        return response()->json($games->stats($club, $d['period'] ?? 'today', $d['trend'] ?? 'days'));
    }
}
