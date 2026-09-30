<?php

namespace App\Http\Controllers;

use App\Models\{Club, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};
use Inertia\Inertia;

class AdminController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless($r->user()->active && $r->user()->role === 'owner', 403);
    }

    private function lockAdministrators(Request $r): void
    {
        // Serialize removals/demotions to prevent concurrent loss of all admins.
        $admins = User::where('role', 'owner')->orderBy('id')->lockForUpdate()->get();
        abort_unless($admins->contains(fn ($u) => $u->id === $r->user()->id && $u->active), 403);
    }

    public function index(Request $r)
    {
        $this->owner($r);
        return Inertia::render('Users', ['users' => User::with('clubs:id,name')->orderBy('name')->paginate(30), 'clubs' => Club::get(['id','name'])]);
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => 'required|string|max:100',
            'email' => ['required','email','max:255',Rule::unique('users')->ignore($user?->id)],
            'password' => ($user ? 'nullable' : 'required').'|string|min:6|max:200',
            'role' => ['required',Rule::in(['owner','lead','specialist','representative'])],
            'club_ids' => 'present|array|max:1000',
            'club_ids.*' => 'integer|distinct|exists:clubs,id',
            ...($user ? ['active' => 'required|boolean'] : []),
        ];
    }

    public function store(Request $r)
    {
        $this->owner($r); $d = $r->validate($this->rules());
        DB::transaction(function () use ($d, $r) {
            $this->lockAdministrators($r);
            $u = new User(); $u->name = $d['name']; $u->email = $d['email'];
            $u->password = Hash::make($d['password']); $u->role = $d['role'];
            $u->save(); $u->clubs()->sync($d['club_ids']);
        });
        return back()->with('success', 'Пользователь создан. Передайте пароль безопасным каналом.');
    }

    public function update(Request $r, User $user)
    {
        $this->owner($r); $d = $r->validate($this->rules($user));
        if ($user->id === $r->user()->id && (! $d['active'] || $d['role'] !== 'owner')) {
            throw ValidationException::withMessages(['active' => 'Нельзя отключить свой аккаунт или снять с себя роль администратора.']);
        }
        DB::transaction(function () use ($user, $d, $r) {
            $this->lockAdministrators($r);
            $u = User::lockForUpdate()->findOrFail($user->id);
            $u->name = $d['name']; $u->email = $d['email']; $u->role = $d['role']; $u->active = $d['active'];
            if (! empty($d['password'])) { $u->password = Hash::make($d['password']); $u->remember_token = Str::random(60); }
            $u->save(); $u->clubs()->sync($d['club_ids']);
        });
        return back()->with('success', 'Пользователь обновлён.');
    }

    public function destroy(Request $r, User $user)
    {
        $this->owner($r);
        if ($user->id === $r->user()->id) throw ValidationException::withMessages(['account' => 'Нельзя удалить свой аккаунт.']);
        DB::transaction(function () use ($r, $user) {
            $this->lockAdministrators($r);
            $u = User::lockForUpdate()->findOrFail($user->id);
            $u->active = false; $u->remember_token = null; $u->save();
            $u->clubs()->detach(); $u->delete();
        });
        return back()->with('success', 'Пользователь удалён. История работ сохранена.');
    }
}
