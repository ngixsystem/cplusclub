<?php
namespace App\Http\Controllers;
use App\Domain\Access\ClubAccess;
use App\Domain\Monitoring\EvaluateSample;
use App\Models\{Club,Equipment};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class UpdatesController extends Controller {
    public function index(Request $r){abort_unless($r->user()->active,403);$ids=ClubAccess::filter(Club::query(),$r->user(),'id')->pluck('id');return Inertia::render('Updates',['clubs'=>Club::whereIn('id',$ids)->get(['id','name']),'published'=>DB::table('published_versions')->get(),'local'=>DB::table('local_versions')->join('equipment','equipment.id','=','local_versions.equipment_id')->whereIn('equipment.club_id',$ids)->select('local_versions.*','equipment.name')->paginate(30),'checks'=>DB::table('version_checks')->join('equipment','equipment.id','=','version_checks.equipment_id')->whereIn('equipment.club_id',$ids)->select('version_checks.*')->orderByDesc('version_checks.id')->limit(50)->get(),'subscriptions'=>DB::table('game_subscriptions')->whereIn('club_id',$ids)->get()]);}
    public function subscribe(Request $r){$d=$r->validate(['club_id'=>'required|integer','app_id'=>['required','integer',Rule::in([730,570])]]);Gate::authorize('view',Club::findOrFail($d['club_id']));abort_unless(ClubAccess::allClubs($r->user()),403);DB::table('game_subscriptions')->insertOrIgnore($d);\App\Jobs\CheckGameVersion::dispatch($d['app_id']);return back()->with('success','Подписка добавлена. Первая версия будет исходным состоянием.');}
    public function verify(Request $r){$d=$r->validate(['equipment_id'=>'required|integer','product'=>['required',Rule::in(['730','570','faceit_file'])],'checked_value'=>'required|string|max:128','result'=>'required|string|min:5|max:4000']);Gate::authorize('update',Equipment::findOrFail($d['equipment_id']));DB::table('version_checks')->insert([...$d,'user_id'=>$r->user()->id,'checked_at'=>now()]);return back()->with('success','Проверка специалиста сохранена отдельно от сведений о версиях.');}
    public function local(Request $r){
        abort_if(strlen($r->getContent())>16384,413);$token=$r->bearerToken();abort_unless($token&&strlen($token)<=256,401);
        $a=DB::table('agents')->where('token_hash',hash('sha256',$token))->whereNull('revoked_at')->first();abort_unless($a,401);
        $d=$r->validate(['equipment_id'=>'required|integer','product'=>['required',Rule::in(['730','570','faceit_file'])],'value'=>['required','string','max:128','regex:/^[a-zA-Z0-9._-]+$/']]);
        DB::transaction(function()use($a,$d){
            $agent=DB::table('agents')->where('id',$a->id)->lockForUpdate()->first();abort_if($agent->revoked_at,401);
            abort_unless(DB::table('agent_equipment')->where('agent_id',$a->id)->where('equipment_id',$d['equipment_id'])->exists(),403);
            abort_unless(Equipment::whereKey($d['equipment_id'])->where('club_id',$a->club_id)->exists(),403);
            $query=DB::table('local_versions')->where('equipment_id',$d['equipment_id'])->where('product',$d['product']);$row=$query->lockForUpdate()->first();
            if(!$row){DB::table('local_versions')->insert([...$d,'observed_at'=>now()]);return;}
            // At least 30 seconds between confirmations; network retries cannot confirm an update.
            if(\Carbon\CarbonImmutable::parse($row->observed_at)->diffInSeconds(now())<30)return;
            $update=['observed_at'=>now(),'candidate'=>null,'confirmations'=>0];
            if($row->value!==$d['value']){
                $count=$row->candidate===$d['value']?$row->confirmations+1:1;$update['candidate']=$d['value'];$update['confirmations']=$count;
                if($count>=2){$update=array_merge($update,['value'=>$d['value'],'candidate'=>null,'confirmations'=>0,'revision'=>$row->revision+1]);app(EvaluateSample::class)->event($a->club_id,$d['product']==='faceit_file'?'faceit_file':'local_build','local:'.$row->id.':'.($row->revision+1),['equipment_id'=>$d['equipment_id'],'product'=>$d['product'],'old'=>$row->value,'new'=>$d['value']]);}
            }$query->update($update);
        });return response()->json(['accepted'=>true]);
    }
}
