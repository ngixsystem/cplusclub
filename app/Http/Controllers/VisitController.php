<?php
namespace App\Http\Controllers;
use App\Domain\Access\ClubAccess;
use App\Domain\Inspections\CompleteInspection;
use App\Models\{Club, Equipment, Visit, Inspection, Ticket, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, DB};
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class VisitController extends Controller {
    public function index(Request $r) {
        abort_unless($r->user()->active,403);
        return Inertia::render('Visits', [
            'visits'=>ClubAccess::filter(Visit::query(),$r->user())->with(['club','specialist'])->latest('id')->paginate(20),
            'clubs'=>ClubAccess::filter(Club::query(),$r->user(),'id')->get(['id','name']),
            'equipment'=>ClubAccess::filter(Equipment::query(),$r->user())->where('type','pc')->orderBy('next_inspection_date')->limit(500)->get(['id','club_id','name','next_inspection_date']),
        ]);
    }
    public function store(Request $r) {
        $d=$r->validate(['club_id'=>'required|integer|exists:clubs,id','planned_date'=>'required|date_format:Y-m-d','equipment_ids'=>'required|array|min:1|max:100','equipment_ids.*'=>'required|integer|distinct']);
        Gate::authorize('view',Club::findOrFail($d['club_id'])); abort_if($r->user()->role==='representative',403);
        abort_unless(Equipment::where('club_id',$d['club_id'])->where('type','pc')->whereIn('id',$d['equipment_ids'])->count()===count($d['equipment_ids']),422);
        $v=DB::transaction(function()use($r,$d){
            $v=Visit::create(['club_id'=>$d['club_id'],'specialist_id'=>$r->user()->id,'planned_date'=>$d['planned_date']]);
            foreach($d['equipment_ids'] as $id) $v->inspections()->create(['club_id'=>$v->club_id,'equipment_id'=>$id]);
            return $v;
        }); return redirect('/visits/'.$v->id);
    }
    public function show(Visit $visit) {
        Gate::authorize('view',$visit);
        return Inertia::render('Visit',['visit'=>$visit->load(['club','inspections.equipment']), 'items'=>CompleteInspection::ITEMS,'canEdit'=>Gate::allows('update',$visit)]);
    }
    public function inspect(Request $r,Inspection $inspection,CompleteInspection $service) {
        Gate::authorize('update',$inspection);
        $d=$r->validate(['status'=>['required',Rule::in(['completed','skipped'])],'checklist'=>'required_if:status,completed|array','work_type'=>['required',Rule::in(['inspection','cleaning','disassembly','replacement'])],'findings'=>'nullable|string|max:10000','work_done'=>'required_if:status,completed|nullable|string|max:10000','verification'=>'required_if:status,completed|nullable|string|max:10000','skip_reason'=>'required_if:status,skipped|nullable|string|max:2000','rescheduled_date'=>'required_if:status,skipped|nullable|date_format:Y-m-d|after_or_equal:today']);
        $service->execute($r->user(),$inspection->id,$d); return back()->with('success','Результат осмотра сохранён.');
    }
    public function approve(Request $r,Inspection $inspection) {
        Gate::authorize('view',$inspection); abort_unless(in_array($r->user()->role,['owner','lead','representative']),403);
        $d=$r->validate(['approval'=>'required|string|min:5|max:2000']);
        DB::transaction(function()use($r,$inspection,$d){
            $i=Inspection::lockForUpdate()->findOrFail($inspection->id); abort_unless($i->status==='pending',409);
            $i->approval=$d['approval'];$i->approved_by=$r->user()->id;$i->approved_at=now();$i->save();
        });return back()->with('success','Согласование зафиксировано.');
    }
    public function issue(Request $r,Inspection $inspection) {
        Gate::authorize('update',$inspection);
        $ticket=DB::transaction(function()use($r,$inspection){
            $i=Inspection::lockForUpdate()->findOrFail($inspection->id);
            if($i->ticket_id) return Ticket::findOrFail($i->ticket_id);
            abort_unless(filled($i->findings),422,'В осмотре нет описания проблемы.');
            $t=Ticket::create(['club_id'=>$i->club_id,'equipment_id'=>$i->equipment_id,'category'=>'Осмотр','description'=>$i->findings,'initiator_id'=>$r->user()->id,'assignee_id'=>$r->user()->id]);
            $i->ticket_id=$t->id;$i->save();return $t;
        });return redirect('/tickets/'.$ticket->id);
    }
    public function complete(Request $r,Visit $visit) {
        Gate::authorize('update',$visit);
        DB::transaction(function()use($visit){
            $v=Visit::lockForUpdate()->findOrFail($visit->id);
            abort_unless($v->status==='planned',409);
            abort_if($v->inspections()->where('status','pending')->exists(),422,'Укажите результат или причину пропуска для каждого ПК.');
            $v->status='completed';$v->completed_at=now();
            $v->report=$v->inspections()->with('equipment')->get()->map(fn($i)=>$i->equipment->name.': '.$i->status.' — '.($i->work_done??$i->skip_reason).' '.($i->verification??''))->join("\n");
            $v->save();
        }); return back()->with('success','Выезд завершён. Отчёт доступен представителю клуба.');
    }
}
