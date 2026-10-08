<?php

namespace App\Http\Controllers;

use App\Domain\Access\ClubAccess;
use App\Domain\Tickets\{TicketStatus, TransitionTicket};
use App\Models\{Club, Equipment, Ticket, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Gate};
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ServiceController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt([...$credentials, 'active' => true])) {
            return back()->withErrors(['email' => 'Неверные данные для входа.']);
        }
        $request->session()->regenerate();
        return redirect()->intended('/');
    }

    public function index(Request $request, string $section = 'overview')
    {
        abort_unless($request->user()->active, 403);
        if ($section === 'overview' && $request->user()->role !== 'specialist') {
            return app(IcafeController::class)->index($request);
        }
        abort_unless(in_array($section, ['overview','clubs','equipment','tickets']), 404);
        $user = $request->user();
        $search = mb_substr((string) $request->query('search', ''), 0, 100);
        $clubs = ClubAccess::filter(Club::query(), $user, 'id');
        $equipment = ClubAccess::filter(Equipment::query(), $user);
        $tickets = Ticket::query()->where(function ($q) use ($user) {
            ClubAccess::filter($q, $user);
            if ($user->role === 'specialist') $q->orWhere('assignee_id', $user->id);
        });
        $stats = [
            'clubs' => (clone $clubs)->count(), 'equipment' => (clone $equipment)->count(),
            'tickets' => (clone $tickets)->whereNotIn('status', ['closed', 'resolved'])->count(),
            'critical' => (clone $tickets)->where('priority', 'critical')->whereNotIn('status', ['closed','resolved'])->count(),
        ];
        $query = match ($section) {
            'clubs' => $clubs->when($search, fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')),
            'equipment' => $equipment->with('club')->when($search, fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')),
            default => $tickets->with(['club', 'assignee', 'equipment'])->when($search, fn ($q) => $q->where('description', 'ilike', '%'.$search.'%')),
        };
        return Inertia::render('Workspace', [
            'section' => $section, 'stats' => $stats, 'search' => $search,
            'rows' => $query->orderByDesc('id')->paginate(20)->withQueryString(),
            'clubs' => ClubAccess::filter(Club::query(), $user, 'id')->orderBy('name')->get(['id', 'name']),
            'ticketComputers' => $section === 'tickets' ? ClubAccess::filter(Equipment::query(), $user)
                ->whereIn('type', ['pc', 'server'])->orderBy('name')->get(['id','club_id','name','type','workstation_number','zone']) : [],
        ]);
    }

    public function club(Request $request)
    {
        Gate::authorize('create', Club::class);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'address' => 'required|string|max:2000',
            'timezone' => 'required|timezone', 'contacts' => 'nullable|string|max:2000',
            'support_hours' => 'required|string|max:255', 'notes' => 'nullable|string|max:10000',
            'icafe_license' => 'nullable|required_with:icafe_token|integer|min:1|unique:clubs,icafe_license',
            'icafe_token' => 'nullable|required_with:icafe_license|string|min:30|max:8192',
            'icafe_currency' => 'sometimes|required|string|regex:/^[A-Z]{3}$/',
        ]);
        if (! empty($data['icafe_license'])) {
            try { app(\App\Domain\Icafe\Client::class)->get((int) $data['icafe_license'], $data['icafe_token'], 'pcs'); }
            catch (\Throwable) { throw \Illuminate\Validation\ValidationException::withMessages(['icafe_token' => 'Не удалось подключиться к iCafeCloud. Проверьте лицензию и токен.']); }
        }
        DB::transaction(function () use ($data) {
            $club = Club::create(\Illuminate\Support\Arr::except($data, ['icafe_license','icafe_token','icafe_currency']));
            if (! empty($data['icafe_license'])) {
                $club->icafe_license = $data['icafe_license'];
                $club->icafe_token = $data['icafe_token'];
                $club->icafe_currency = $data['icafe_currency'] ?? 'UZS';
                $club->save();
            }
        });
        return back()->with('success', 'Клуб добавлен.');
    }

    public function equipment(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id', 'name' => 'required|string|max:255',
            'workstation_number' => ['nullable', 'string', 'max:80', Rule::unique('equipment')->where('club_id', $request->input('club_id'))],
            'type' => ['required', Rule::in(['pc','server','switch','router','ups','peripheral'])],
            'zone' => 'nullable|string|max:255', 'next_inspection_date' => 'required|date_format:Y-m-d',
        ]);
        Gate::authorize('view', Club::findOrFail($data['club_id']));
        abort_if($request->user()->role === 'representative', 403);
        Equipment::create($data);
        return back()->with('success', 'Оборудование добавлено.');
    }

    public function ticket(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'required|integer|exists:clubs,id', 'equipment_id' => 'nullable|integer|min:1',
            'category' => 'required|string|max:80', 'description' => 'required|string|max:10000',
            'priority' => ['required', Rule::in(['low','normal','high','critical'])],
        ]);
        Gate::authorize('view', Club::findOrFail($data['club_id']));
        if (! empty($data['equipment_id'])) {
            abort_unless(Equipment::whereKey($data['equipment_id'])->where('club_id', $data['club_id'])->exists(), 422);
        }
        $ticket = DB::transaction(function () use ($request, $data) {
            $ticket = Ticket::create([...$data, 'initiator_id' => $request->user()->id]);
            if ($ticket->priority === 'critical') {
                $ticket->assignee_id = User::where('role', 'lead')->where('active', true)->orderBy('id')->value('id');
                $ticket->save();
                DB::table('outbox_events')->insert([
                    'club_id' => $ticket->club_id, 'kind' => 'critical_ticket',
                    'dedup_key' => 'critical-ticket:'.$ticket->id,
                    'payload' => json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
            return $ticket;
        });
        return redirect('/tickets/'.$ticket->id)->with('success', 'Заявка создана.');
    }

    public function show(Ticket $ticket)
    {
        Gate::authorize('view', $ticket);
        $ticket->load(['club', 'equipment', 'assignee', 'events']);
        $assignable = User::where('active', true)->where(function ($q) use ($ticket) {
            $q->whereIn('role', ['owner','lead'])->orWhere(function ($q) use ($ticket) {
                $q->where('role', 'specialist')->whereHas('clubs', fn ($q) => $q->where('clubs.id', $ticket->club_id));
            });
        })->get(['id','name']);
        return Inertia::render('Ticket', [
            'ticket' => $ticket, 'assignable' => Gate::allows('update', $ticket) ? $assignable : [],
            'attachments' => \App\Models\Attachment::where('ticket_id',$ticket->id)->get(),
            'workLogs' => DB::table('work_logs')->where('ticket_id',$ticket->id)->get(),
            'canEdit' => Gate::allows('update', $ticket),
            'canDecide' => Gate::allows('decide', $ticket),
            'proposals' => DB::table('ticket_proposals as p')->join('users as a', 'a.id', '=', 'p.author_id')
                ->leftJoin('users as d', 'd.id', '=', 'p.decided_by')->where('p.ticket_id', $ticket->id)
                ->orderByDesc('p.id')->get(['p.*', 'a.name as author_name', 'd.name as decision_name']),
            'transitions' => array_values(array_map(fn ($s) => $s->value,
                array_filter(TicketStatus::cases(), fn ($s) => $s !== TicketStatus::Approval && $ticket->status !== 'approval'
                    && !($s === TicketStatus::Closed && DB::table('ticket_proposals')->where('ticket_id', $ticket->id)->exists())
                    && TicketStatus::from($ticket->status)->canMoveTo($s)))),
        ]);
    }

    public function transition(Request $request, Ticket $ticket, TransitionTicket $service)
    {
        Gate::authorize('update', $ticket);
        $data = $request->validate([
            'status' => ['required', Rule::enum(TicketStatus::class)], 'version' => 'required|integer|min:1',
            'work_result' => 'nullable|string|max:10000', 'verification_result' => 'nullable|string|max:10000',
            'cause' => 'nullable|string|max:10000', 'solution' => 'nullable|string|max:10000', 'comment' => 'nullable|string|max:5000',
        ]);
        $service->execute($request->user(), $ticket->id, TicketStatus::from($data['status']), $data['version'], $data);
        return back()->with('success', 'Статус изменён, запись добавлена в историю.');
    }

    public function assign(Request $request, Ticket $ticket)
    {
        Gate::authorize('update', $ticket);
        abort_unless(ClubAccess::allClubs($request->user()), 403);
        $data = $request->validate(['assignee_id' => 'required|integer|exists:users,id', 'version' => 'required|integer']);
        $assignee = User::findOrFail($data['assignee_id']);
        abort_unless($assignee->active && $assignee->role !== 'representative' && ClubAccess::allows($assignee, $ticket->club_id), 422);
        DB::transaction(function () use ($ticket, $data, $request) {
            $locked = Ticket::lockForUpdate()->findOrFail($ticket->id);
            abort_if($locked->version !== $data['version'], 409);
            $locked->assignee_id = $data['assignee_id'];
            $locked->version++;
            $locked->save();
            $locked->events()->create(['actor_id' => $request->user()->id, 'from_status' => $locked->status,
                'to_status' => $locked->status, 'comment' => 'Назначен исполнитель #'.$data['assignee_id']]);
        });
        return back()->with('success', 'Исполнитель назначен.');
    }
}
