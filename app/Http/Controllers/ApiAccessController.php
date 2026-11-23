<?php

namespace App\Http\Controllers;

use App\Models\{ApiClient, Club};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Owner-only management of read-only API tokens for external systems. */
class ApiAccessController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless($r->user()->active && $r->user()->role === 'owner', 403);
    }

    public function index(Request $r)
    {
        $this->owner($r);
        $day = DB::table('api_requests')->where('created_at', '>=', now()->subDay())
            ->selectRaw('api_client_id, count(*) as n, count(*) filter (where status >= 400) as errors')->groupBy('api_client_id')->get()->keyBy('api_client_id');
        $clients = ApiClient::with('club:id,name')->orderByRaw('revoked_at is not null')->orderByDesc('id')->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'club' => $c->club?->name, 'hint' => '…'.$c->token_hint, 'ips' => $c->ips(), 'scopes' => $c->scopes,
            'last_used_at' => $c->last_used_at, 'last_ip' => $c->last_ip, 'revoked_at' => $c->revoked_at, 'created_at' => $c->created_at,
            'requests_24h' => (int) ($day[$c->id]->n ?? 0), 'errors_24h' => (int) ($day[$c->id]->errors ?? 0),
        ]);
        $log = DB::table('api_requests as q')->join('api_clients as c', 'c.id', '=', 'q.api_client_id')
            ->select('q.id', 'c.name', 'q.method', 'q.path', 'q.status', 'q.ip', 'q.ms', 'q.created_at')->orderByDesc('q.id')->limit(30)->get();
        return Inertia::render('ApiAccess', ['clients' => $clients, 'log' => $log, 'clubs' => Club::orderBy('name')->get(['id', 'name']),
            'scopes' => ApiClient::SCOPES, 'baseUrl' => rtrim(config('app.url'), '/').'/api/ext/v1', 'newToken' => session('newToken')]);
    }

    public function store(Request $r)
    {
        $this->owner($r);
        $d = $r->validate(['name' => 'required|string|max:120', 'club_id' => 'required|integer|exists:clubs,id',
            'allowed_ips' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) {
                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $ip) {
                    $addr = explode('/', $ip)[0];
                    if (! filter_var($addr, FILTER_VALIDATE_IP)) $fail('Неверный IP-адрес: '.$ip);
                }
            }],
            'scopes' => 'required|array|min:1', 'scopes.*' => [Rule::in(ApiClient::SCOPES)]]);
        [$client, $token] = ApiClient::issue(['name' => $d['name'], 'club_id' => $d['club_id'], 'scopes' => array_values(array_unique($d['scopes'])),
            'allowed_ips' => implode(',', array_filter(array_map('trim', explode(',', (string) ($d['allowed_ips'] ?? ''))))) ?: null, 'created_by' => $r->user()->id]);
        DB::table('audit_logs')->insert(['actor_id' => $r->user()->id, 'club_id' => $client->club_id, 'action' => 'api_client.create', 'subject' => (string) $client->id]);
        return back()->with(['success' => 'Токен создан. Скопируйте его сейчас — повторно он не показывается.', 'newToken' => ['id' => $client->id, 'token' => $token]]);
    }

    /** Change what an existing token may read (the token itself stays the same). */
    public function scopes(Request $r, ApiClient $client)
    {
        $this->owner($r);
        abort_if($client->revoked_at, 409, 'Токен отозван.');
        $d = $r->validate(['scopes' => 'required|array|min:1', 'scopes.*' => [Rule::in(ApiClient::SCOPES)]]);
        $client->scopes = array_values(array_unique($d['scopes']));
        $client->save();
        DB::table('audit_logs')->insert(['actor_id' => $r->user()->id, 'club_id' => $client->club_id, 'action' => 'api_client.scopes', 'subject' => (string) $client->id]);
        return back()->with('success', 'Доступ токена обновлён.');
    }

    public function revoke(Request $r, ApiClient $client)
    {
        $this->owner($r);
        if (! $client->revoked_at) { $client->revoked_at = now(); $client->save(); }
        DB::table('audit_logs')->insert(['actor_id' => $r->user()->id, 'club_id' => $client->club_id, 'action' => 'api_client.revoke', 'subject' => (string) $client->id]);
        return back()->with('success', 'Токен отозван: запросы с ним больше не принимаются.');
    }
}
