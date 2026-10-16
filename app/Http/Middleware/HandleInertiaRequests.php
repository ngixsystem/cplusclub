<?php

namespace App\Http\Middleware;

use App\Domain\Access\ClubAccess;
use App\Models\{Club, Ticket};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'role', 'avatar_url')],
            'flash' => ['success' => fn () => $request->session()->get('success')],
            // Header notification counters; scoped by club membership like every other list.
            'attention' => function () use ($request) {
                $user = $request->user();
                if (! $user?->active) return null;
                $clubIds = ClubAccess::filter(Club::query(), $user, 'id')->select('id');
                $critical = Ticket::query()->where('priority', 'critical')->whereNotIn('status', ['resolved', 'closed'])
                    ->where(function ($q) use ($user) {
                        ClubAccess::filter($q, $user);
                        if ($user->role === 'specialist') $q->orWhere('assignee_id', $user->id);
                    })->count();
                $alerts = DB::table('alerts')->where('state', 'active')->whereIn('club_id', $clubIds)->count();
                return ['critical' => $critical, 'alerts' => $alerts];
            },
        ];
    }
}
