<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClubAccess;
use App\Domain\Overview\OverviewSnapshot;
use App\Models\Club;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class OverviewController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->active, 403);
        // The iCafeCloud cash dashboard stays limited to roles that may see club finances.
        $finance = in_array($user->role, ['owner', 'lead', 'representative'], true);

        return Inertia::render('Overview', [
            'overview' => (new OverviewSnapshot($user))->toArray(),
            'financeClubs' => $finance ? ClubAccess::filter(Club::query(), $user, 'id')->orderBy('name')
                ->get(['id', 'name', 'icafe_license', 'icafe_currency', 'timezone']) : [],
            'canManage' => ClubAccess::allClubs($user),
        ]);
    }
}
