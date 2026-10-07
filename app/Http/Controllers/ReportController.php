<?php
namespace App\Http\Controllers;

use App\Domain\Access\ClubAccess;
use App\Models\{Club, Ticket};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ReportController extends Controller
{
    private function context(Request $r): array
    {
        abort_unless($r->user()->active, 403);
        $filters = $r->validate([
            'view'=>['nullable',Rule::in(['closed','work'])], 'club_id'=>'nullable|integer|min:1',
            'equipment_id'=>'nullable|integer|min:1', 'assignee_id'=>'nullable|integer|min:1',
            'from'=>'nullable|date_format:Y-m-d',
            'to'=>['nullable','date_format:Y-m-d',...($r->filled('from')?['after_or_equal:from']:[])],
        ]);
        $filters['view'] = $filters['view'] ?? 'closed';
        $clubs = ClubAccess::filter(Club::query(), $r->user(), 'id')->orderBy('name')->get(['id','name']);
        $ids = $clubs->pluck('id');
        if (!empty($filters['club_id'])) abort_unless($ids->contains((int)$filters['club_id']),403);
        $equipment = DB::table('equipment')->whereIn('club_id',$ids)->orderBy('name')->get(['id','name','club_id']);
        $assignees = DB::table('users')->whereIn('id',DB::table('tickets')->whereIn('club_id',$ids)->whereNotNull('assignee_id')->select('assignee_id'))->orderBy('name')->get(['id','name']);
        if (!empty($filters['equipment_id'])) abort_unless($equipment->pluck('id')->contains((int)$filters['equipment_id']),403);
        if (!empty($filters['assignee_id'])) abort_unless($assignees->pluck('id')->contains((int)$filters['assignee_id']),403);
        return [$filters,$clubs,$equipment,$assignees];
    }

    private function query(array $f, $clubs)
    {
        $closed = $f['view'] === 'closed';
        $q = DB::table('tickets')->join('clubs','clubs.id','=','tickets.club_id')
            ->leftJoin('equipment','equipment.id','=','tickets.equipment_id')
            ->leftJoin('users as assignee','assignee.id','=','tickets.assignee_id')
            ->whereIn('tickets.club_id',$clubs->pluck('id'));
        foreach (['club_id','equipment_id','assignee_id'] as $field) {
            if (!empty($f[$field])) $q->where('tickets.'.$field,$f[$field]);
        }
        if ($closed) {
            $minutes = DB::table('work_logs')->select('ticket_id')->selectRaw('SUM(minutes) as minutes')->groupBy('ticket_id');
            $byCurrency = DB::table('work_logs')->select('ticket_id','currency')->selectRaw('SUM(cost_minor) as cost_minor')->groupBy('ticket_id','currency');
            $costs = DB::query()->fromSub($byCurrency,'c')->select('ticket_id')
                ->selectRaw("jsonb_agg(jsonb_build_object('currency',currency,'cost_minor',cost_minor) ORDER BY currency) as costs")->groupBy('ticket_id');
            $q->where('tickets.status','closed')->leftJoinSub($minutes,'time','time.ticket_id','=','tickets.id')
                ->leftJoinSub($costs,'money','money.ticket_id','=','tickets.id')
                ->select('tickets.id','tickets.id as ticket_id','clubs.name as club_name','equipment.name as equipment_name','assignee.name as assignee_name',
                    'tickets.description','tickets.cause','tickets.solution','tickets.work_result','tickets.verification_result','tickets.closed_at')
                ->selectRaw("COALESCE(time.minutes,0) as minutes, COALESCE(money.costs,'[]'::jsonb) as costs");
        } else {
            $q->join('work_logs','work_logs.ticket_id','=','tickets.id')->select('work_logs.*','clubs.name as club_name','equipment.name as equipment_name','assignee.name as assignee_name');
        }
        $date = $closed ? 'tickets.closed_at' : 'work_logs.created_at';
        if (!empty($f['from'])) $q->where($date,'>=',CarbonImmutable::parse($f['from'],'Asia/Tashkent')->startOfDay()->utc());
        if (!empty($f['to'])) $q->where($date,'<',CarbonImmutable::parse($f['to'],'Asia/Tashkent')->startOfDay()->addDay()->utc());
        return $q;
    }

    public function index(Request $r)
    {
        [$f,$clubs,$equipment,$assignees]=$this->context($r);
        $query=$this->query($f,$clubs);
        if ($f['view']==='closed') {
            $totals=DB::table('work_logs')->whereIn('ticket_id',(clone $query)->select('tickets.id'));
        } else {
            $totals=clone $query;
        }
        $totals=$totals->select('work_logs.currency')->selectRaw('SUM(work_logs.minutes) as minutes, SUM(work_logs.cost_minor) as cost_minor')->groupBy('work_logs.currency')->orderBy('work_logs.currency')->get();
        $rows=$query->orderByDesc($f['view']==='closed'?'tickets.closed_at':'work_logs.created_at')
            ->orderByDesc($f['view']==='closed'?'tickets.id':'work_logs.id')->paginate(30)->withQueryString();
        if ($f['view']==='closed') $rows->through(function($row){$row->costs=json_decode($row->costs,true);return $row;});
        return Inertia::render('Reports',compact('rows','totals','clubs','equipment','assignees')+['filters'=>$f]);
    }

    public function log(Request $r,Ticket $ticket)
    {
        Gate::authorize('update',$ticket);
        $d=$r->validate(['minutes'=>'required|integer|min:1|max:1440','cost_minor'=>'required|integer|min:0|max:100000000000','currency'=>'required|string|regex:/^[A-Z]{3}$/','actions'=>'required|string|max:5000','consumables'=>'nullable|string|max:2000']);
        DB::table('work_logs')->insert([...$d,'ticket_id'=>$ticket->id,'user_id'=>$r->user()->id,'created_at'=>now()]);
        return back()->with('success','Время и стоимость записаны.');
    }

    public function export(Request $r)
    {
        [$f,$clubs]=$this->context($r);$query=$this->query($f,$clubs);$closed=$f['view']==='closed';
        return response()->streamDownload(function()use($query,$closed){
            $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
            $write=function(array $cells)use($out){$safe=array_map(fn($v)=>preg_match('/^[\s]*[=+@\-]|^[\t\r\n]/u',(string)$v)?"'".$v:$v,$cells);fputcsv($out,$safe,',','"','');};
            $write($closed?['Клуб','Заявка','ПК','Исполнитель','Закрыта (Asia/Tashkent)','Проблема','Причина','Решение','Работы','Проверка','Минуты','Расходы по валютам (минимальные единицы)']:['Клуб','Заявка','ПК','Исполнитель','Дата (Asia/Tashkent)','Минуты','Стоимость (минимальные единицы)','Валюта','Действия','Расходники']);
            foreach($query->orderByDesc($closed?'tickets.closed_at':'work_logs.created_at')->orderByDesc($closed?'tickets.id':'work_logs.id')->cursor() as $row){
                $date=$closed?$row->closed_at:$row->created_at;
                $base=[$row->club_name,$row->ticket_id,$row->equipment_name,$row->assignee_name,$date?CarbonImmutable::parse($date)->setTimezone('Asia/Tashkent')->format('Y-m-d H:i:s'):''];
                $write($closed?[...$base,$row->description,$row->cause,$row->solution,$row->work_result,$row->verification_result,$row->minutes,$row->costs]:[...$base,$row->minutes,$row->cost_minor,$row->currency,$row->actions,$row->consumables]);
            }fclose($out);
        },$closed?'cclub-closed-tickets.csv':'cclub-work.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
    }
}
