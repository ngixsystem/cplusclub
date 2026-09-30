<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClubAccess;
use App\Domain\Icafe\{Client, Dashboard};
use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\{Rule, ValidationException};
use Inertia\Inertia;

final class IcafeController extends Controller
{
    private function authorizeFinance(Request $request, ?Club $club = null): void
    {
        abort_unless($request->user()->active && in_array($request->user()->role, ['owner','lead','representative'], true), 403);
        if ($club) Gate::authorize('view', $club);
    }

    public function index(Request $request)
    {
        $this->authorizeFinance($request);
        return Inertia::render('Dashboard', ['clubs' => ClubAccess::filter(Club::query(), $request->user(), 'id')
            ->orderBy('name')->get(['id','name','icafe_license','icafe_currency','timezone'])]);
    }

    public function data(Request $request, Club $club, Dashboard $dashboard)
    {
        $this->authorizeFinance($request, $club);
        if (! $club->icafe_license || ! $club->icafe_token) return response()->json(['connected' => false]);
        return response()->json(['connected' => true, ...$dashboard->snapshot($club)])->header('Cache-Control', 'no-store');
    }

    public function shift(Request $request, Club $club, string $shift, Dashboard $dashboard)
    {
        $this->authorizeFinance($request, $club);
        abort_unless($club->icafe_license && $club->icafe_token, 404);
        $row = collect($dashboard->snapshot($club)['shifts'] ?? [])->firstWhere('id', $shift);
        abort_unless($row, 404);
        return response()->json($dashboard->detail($club, $row))->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, Club $club, Client $client)
    {
        Gate::authorize('update', $club);
        $data = $request->validate([
            'icafe_license' => ['required','integer','min:1',Rule::unique('clubs')->ignore($club->id)],
            'icafe_token' => 'nullable|string|min:30|max:8192',
            'icafe_currency' => 'required|string|regex:/^[A-Z]{3}$/',
        ]);
        $token = $data['icafe_token'] ?? $club->icafe_token;
        if (! $token) throw ValidationException::withMessages(['icafe_token' => 'Укажите API-токен.']);
        try { $client->get((int) $data['icafe_license'], $token, 'pcs'); }
        catch (\Throwable) { throw ValidationException::withMessages(['icafe_token' => 'Не удалось подключиться. Проверьте лицензию и токен.']); }
        $club->icafe_license = $data['icafe_license'];
        $club->icafe_token = $token;
        $club->icafe_currency = $data['icafe_currency'];
        $club->save();
        return back()->with('success', 'iCafeCloud подключён. Дашборд доступен на главной странице.');
    }
}
