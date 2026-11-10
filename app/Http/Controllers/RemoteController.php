<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClubAccess;
use App\Domain\Remote\{Presence, RustDesk};
use App\Models\{Club, RemoteHost};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Remote access to club computers through the self-hosted RustDesk server.
 * Operators (owner, lead, specialists with access to the club) connect; owner and lead manage.
 */
class RemoteController extends Controller
{
    private const ROLES = ['owner', 'lead', 'specialist'];

    private function operator(Request $r): void
    {
        abort_unless($r->user()->active && in_array($r->user()->role, self::ROLES, true), 403);
    }

    private function manager(Request $r): void
    {
        abort_unless($r->user()->active && ClubAccess::allClubs($r->user()), 403);
    }

    private function host(Request $r, int $id): RemoteHost
    {
        $h = RemoteHost::findOrFail($id);
        Gate::authorize('view', Club::findOrFail($h->club_id));
        return $h;
    }

    public function index(Request $r)
    {
        $this->operator($r);
        $clubs = ClubAccess::filter(Club::query(), $r->user(), 'id')->orderBy('name')->get(['id', 'name']);
        $last = DB::table('remote_connections as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->selectRaw('DISTINCT ON (c.remote_host_id) c.remote_host_id, c.created_at, u.name')->orderBy('c.remote_host_id')->orderByDesc('c.created_at')->get()->keyBy('remote_host_id');
        $hosts = RemoteHost::whereIn('club_id', $clubs->pluck('id'))->orderBy('name')->get()->map(fn ($h) => [
            'id' => $h->id, 'club_id' => $h->club_id, 'name' => $h->name, 'rustdesk_id' => RustDesk::formatId($h->rustdesk_id), 'hostname' => $h->hostname,
            'enrolled_at' => $h->enrolled_at, 'pending' => ! $h->rustdesk_id,
            'online' => $h->online, 'last_seen_at' => $h->last_seen_at, 'status_changed_at' => $h->status_changed_at,
            'last' => isset($last[$h->id]) ? ['at' => $last[$h->id]->created_at, 'by' => $last[$h->id]->name] : null,
        ]);
        return Inertia::render('Remote', ['clubs' => $clubs, 'hosts' => $hosts, 'canManage' => ClubAccess::allClubs($r->user()),
            'server' => RustDesk::host(), 'installCommand' => session('installCommand'), 'installFor' => session('installFor')]);
    }

    /** A new computer: a password and a one-time token for the installer command. */
    public function store(Request $r)
    {
        $this->manager($r);
        $d = $r->validate(['club_id' => 'required|integer|exists:clubs,id', 'name' => 'required|string|max:80', 'rustdesk_id' => ['nullable', 'regex:/^[\d\s]{6,16}$/']]);
        $h = new RemoteHost();
        $h->club_id = $d['club_id']; $h->name = $d['name']; $h->password = RustDesk::password(); $h->created_by = $r->user()->id;
        if (! empty($d['rustdesk_id'])) { $h->rustdesk_id = preg_replace('/\D/', '', $d['rustdesk_id']); $h->enrolled_at = now(); }
        $h->save();
        if ($h->rustdesk_id) return back()->with('success', 'Компьютер добавлен. Задайте в его RustDesk постоянный пароль из карточки.');
        return $this->issueToken($h);
    }

    public function update(Request $r, int $host)
    {
        $this->manager($r);
        $h = $this->host($r, $host);
        if ($r->boolean('delete')) { $h->delete(); return back()->with('success', 'Компьютер удалён из удалённого доступа.'); }
        if ($r->boolean('reinstall')) return $this->issueToken($h);
        $d = $r->validate(['name' => 'required|string|max:80', 'rustdesk_id' => ['nullable', 'regex:/^[\d\s]{6,16}$/']]);
        $h->name = $d['name'];
        if (array_key_exists('rustdesk_id', $d)) $h->rustdesk_id = $d['rustdesk_id'] ? preg_replace('/\D/', '', $d['rustdesk_id']) : null;
        $h->save();
        return back()->with('success', 'Сохранено.');
    }

    private function issueToken(RemoteHost $h)
    {
        $token = Str::random(40);
        $h->enroll_token_hash = hash('sha256', $token); $h->enroll_expires_at = now()->addDay();
        $h->save();
        $url = rtrim(config('app.url'), '/').'/rustdesk/install.ps1';
        $cmd = "& ([scriptblock]::Create((irm '{$url}'))) -Token '{$token}'";
        return back()->with(['installCommand' => $cmd, 'installFor' => $h->id]);
    }

    /** The link that opens RustDesk on the operator's computer and connects. Logged. */
    public function connect(Request $r, int $host)
    {
        $this->operator($r);
        $h = $this->host($r, $host);
        abort_unless($h->rustdesk_id, 409, 'Компьютер ещё не зарегистрирован: запустите на нём команду установки.');
        DB::table('remote_connections')->insert(['remote_host_id' => $h->id, 'user_id' => $r->user()->id, 'ip' => $r->ip(), 'created_at' => now()]);
        return response()->json(['url' => RustDesk::connectUrl($h), 'id' => RustDesk::formatId($h->rustdesk_id)]);
    }

    /** Password for typing it by hand (managers only). */
    public function password(Request $r, int $host)
    {
        $this->manager($r);
        $h = $this->host($r, $host);
        return response()->json(['password' => $h->password]);
    }

    /** Live online status for the hosts the user can see; asks the RustDesk server at most every 10 s. */
    public function presence(Request $r)
    {
        $this->operator($r);
        $clubs = ClubAccess::filter(Club::query(), $r->user(), 'id')->pluck('id');
        \Illuminate\Support\Facades\Cache::lock('remote-presence', 5)->get(function () {
            if (\Illuminate\Support\Facades\Cache::add('remote-presence-fresh', 1, 10)) Presence::refresh();
        });
        return response()->json(['hosts' => RemoteHost::whereIn('club_id', $clubs)->whereNotNull('rustdesk_id')->get()
            ->map(fn ($h) => ['id' => $h->id, 'online' => $h->online, 'last_seen_at' => $h->last_seen_at, 'status_changed_at' => $h->status_changed_at])]);
    }

    /** Status poll while the installer runs. */
    public function status(Request $r, int $host)
    {
        $this->operator($r);
        $h = $this->host($r, $host);
        return response()->json(['enrolled' => (bool) $h->rustdesk_id, 'rustdesk_id' => RustDesk::formatId($h->rustdesk_id), 'hostname' => $h->hostname]);
    }

    /** Public: the installer script, with this server's address and key filled in (no secrets). */
    public function script()
    {
        $ps = file_get_contents(resource_path('scripts/rustdesk-install.ps1'));
        $ps = str_replace(['__API__', '__CONFIG__'], [rtrim(config('app.url'), '/'), RustDesk::configString()], $ps);
        return response($ps, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** Public API: the installer reports the RustDesk ID with its one-time token and gets the password. */
    public function enroll(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|size:40', 'id' => 'required|regex:/^\d{6,16}$/', 'hostname' => 'nullable|string|max:120']);
        return DB::transaction(function () use ($d) {
            $h = RemoteHost::where('enroll_token_hash', hash('sha256', $d['token']))->lockForUpdate()->first();
            abort_unless($h && $h->enroll_expires_at && $h->enroll_expires_at->isFuture(), 403, 'Ссылка установки недействительна или устарела. Возьмите новую команду в админке.');
            $h->rustdesk_id = $d['id']; $h->hostname = $d['hostname'] ?? null; $h->enrolled_at = now();
            $h->enroll_token_hash = null; $h->enroll_expires_at = null;
            $h->save();
            return response()->json(['password' => $h->password]);
        });
    }
}
