<?php
namespace App\Http\Controllers;
use App\Domain\Access\ClubAccess;
use App\Models\{Club,Ticket};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Inertia\Inertia;
class ReportController extends Controller {
    private function query(Request $r){abort_unless($r->user()->active,403);$ids=ClubAccess::filter(Club::query(),$r->user(),'id')->pluck('id');return DB::table('work_logs')->join('tickets','tickets.id','=','work_logs.ticket_id')->join('clubs','clubs.id','=','tickets.club_id')->whereIn('tickets.club_id',$ids)->select('work_logs.*','clubs.name as club_name');}
    public function index(Request $r){$query=$this->query($r);$totals=(clone $query)->select('work_logs.currency')->selectRaw('SUM(minutes) as minutes, SUM(cost_minor) as cost_minor')->groupBy('work_logs.currency')->get();return Inertia::render('Reports',['rows'=>$query->orderByDesc('work_logs.id')->paginate(30),'totals'=>$totals]);}
    public function log(Request $r,Ticket $ticket){Gate::authorize('update',$ticket);$d=$r->validate(['minutes'=>'required|integer|min:1|max:1440','cost_minor'=>'required|integer|min:0|max:100000000000','currency'=>'required|string|regex:/^[A-Z]{3}$/','actions'=>'required|string|max:5000','consumables'=>'nullable|string|max:2000']);DB::table('work_logs')->insert([...$d,'ticket_id'=>$ticket->id,'user_id'=>$r->user()->id,'created_at'=>now()]);return back()->with('success','Время и стоимость записаны.');}
    public function export(Request $r){$query=$this->query($r);return response()->streamDownload(function()use($query){$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Клуб','Заявка','Минуты','Стоимость (минимальные единицы)','Валюта','Действия'],',','"','');foreach($query->orderBy('work_logs.id')->cursor() as $row){$safe=fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v;fputcsv($out,[$safe($row->club_name),$row->ticket_id,$row->minutes,$row->cost_minor,$row->currency,$safe($row->actions)],',','"','');}fclose($out);},'cclub-work.csv',['Content-Type'=>'text/csv; charset=UTF-8']);}
}
