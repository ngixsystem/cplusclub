<?php
namespace App\Http\Controllers;
use App\Domain\Access\ClubAccess;
use App\Models\{Club,Equipment,TelegramChannel};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class MonitoringController extends Controller {
    private function manage(Request $r,int $id):void {Gate::authorize('view',Club::findOrFail($id));abort_unless(ClubAccess::allClubs($r->user()),403);}
    public function index(Request $r){
        abort_unless($r->user()->active,403);$ids=ClubAccess::filter(Club::query(),$r->user(),'id')->pluck('id');
        return Inertia::render('Monitoring',[
            'clubs'=>Club::whereIn('id',$ids)->get(['id','name']),
            'equipment'=>Equipment::whereIn('club_id',$ids)->orderBy('id')->paginate(30),
            'alerts'=>DB::table('alerts')->whereIn('club_id',$ids)->where('state','active')->limit(100)->get(),
            'agents'=>DB::table('agents')->whereIn('club_id',$ids)->select('id','club_id','name','revoked_at','last_seen_at')->limit(100)->get(),
            'rules'=>DB::table('monitor_rules')->whereIn('club_id',$ids)->get(),
            'agentSecret'=>session('agentSecret'),
        ]);
    }
    public function register(Request $r){
        $d=$r->validate(['club_id'=>'required|integer','equipment_id'=>'required|integer','name'=>'required|string|max:100']);$this->manage($r,$d['club_id']);
        abort_unless(Equipment::where('club_id',$d['club_id'])->whereKey($d['equipment_id'])->exists(),422);
        $id=(string)Str::uuid();$token=Str::random(64);
        DB::transaction(function()use($id,$token,$d,$r){DB::table('agents')->insert(['id'=>$id,'club_id'=>$d['club_id'],'name'=>$d['name'],'token_hash'=>hash('sha256',$token),'created_at'=>now(),'updated_at'=>now()]);DB::table('agent_equipment')->insert(['agent_id'=>$id,'equipment_id'=>$d['equipment_id']]);DB::table('audit_logs')->insert(['actor_id'=>$r->user()->id,'club_id'=>$d['club_id'],'action'=>'agent.register','subject'=>$id]);});
        return back()->with('agentSecret',['id'=>$id,'token'=>$token,'equipment_id'=>$d['equipment_id']])->with('success','Токен показан один раз. Сохраните его только на этом ПК.');
    }
    public function revoke(Request $r,string $id){$agent=DB::table('agents')->find($id);abort_unless($agent,404);$this->manage($r,$agent->club_id);DB::transaction(function()use($id,$r,$agent){DB::table('agents')->where('id',$id)->lockForUpdate()->first();DB::table('agents')->where('id',$id)->update(['revoked_at'=>now()]);DB::table('audit_logs')->insert(['actor_id'=>$r->user()->id,'club_id'=>$agent->club_id,'action'=>'agent.revoke','subject'=>$id]);});return back()->with('success','Токен отозван. Для ротации зарегистрируйте новый агент.');}
    public function rule(Request $r){$d=$r->validate(['club_id'=>'required|integer','metric'=>['required',Rule::in(['cpu_temp','gpu_temp','cpu_load','gpu_load','ram_used_percent','disk_free_percent'])],'trigger_value'=>'required|numeric|between:0,150','recovery_value'=>'required|numeric|between:0,150','hold_seconds'=>'required|integer|between:0,3600']);$this->manage($r,$d['club_id']);abort_unless($d['metric']==='disk_free_percent'?$d['recovery_value']>$d['trigger_value']:$d['recovery_value']<$d['trigger_value'],422,'Порог восстановления должен отличаться в безопасную сторону.');DB::table('monitor_rules')->updateOrInsert(['club_id'=>$d['club_id'],'metric'=>$d['metric']],$d);return back()->with('success','Порог сохранён.');}
    public function maintenance(Request $r){$d=$r->validate(['club_id'=>'required|integer','starts_at'=>'required|date','ends_at'=>'required|date|after:starts_at','reason'=>'required|string|max:1000']);$this->manage($r,$d['club_id']);DB::table('maintenance_windows')->insert($d);return back()->with('success','Интервал обслуживания сохранён.');}
}
