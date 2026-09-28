<?php
namespace App\Http\Controllers;
use App\Domain\Access\ClubAccess;
use App\Domain\Monitoring\EvaluateSample;
use App\Models\{Club,TelegramChannel};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class IntegrationController extends Controller {
    public const KINDS=['published_build','local_build','faceit_file','monitor_problem','monitor_recovered','critical_ticket','inspection_due','source_unavailable','test'];
    public function index(Request $r){abort_unless($r->user()->active&&ClubAccess::allClubs($r->user()),403);return Inertia::render('Integrations',['clubs'=>Club::get(['id','name']),'channels'=>TelegramChannel::get(),'kinds'=>self::KINDS,'deliveries'=>DB::table('deliveries')->orderByDesc('id')->paginate(30),'attempts'=>DB::table('delivery_attempts')->orderByDesc('id')->limit(50)->get(),'transport'=>config('services.telegram.transport')]);}
    public function store(Request $r){abort_unless($r->user()->active&&ClubAccess::allClubs($r->user()),403);$d=$r->validate(['club_id'=>'required|integer|exists:clubs,id','name'=>'required|string|max:100','bot_token'=>'required|string|regex:/^[0-9]+:[A-Za-z0-9_-]+$/|max:200','chat_id'=>'required|string|regex:/^-?[0-9]+$/|max:80','kinds'=>'required|array|min:1','kinds.*'=>[Rule::in(self::KINDS)]]);Gate::authorize('view',Club::findOrFail($d['club_id']));$channel=new TelegramChannel();foreach($d as $key=>$value)$channel->$key=$value;$channel->save();DB::table('audit_logs')->insert(['actor_id'=>$r->user()->id,'club_id'=>$channel->club_id,'action'=>'telegram.create','subject'=>(string)$channel->id]);return back()->with('success','Канал сохранён. Токен зашифрован и не отображается повторно.');}
    public function test(Request $r,TelegramChannel $channel){abort_unless($r->user()->active&&ClubAccess::allClubs($r->user()),403);Gate::authorize('view',Club::findOrFail($channel->club_id));abort_unless(in_array('test',$channel->kinds,true),422,'Включите подписку test.');app(EvaluateSample::class)->event($channel->club_id,'test','telegram-test:'.(string)\Illuminate\Support\Str::uuid(),['message'=>'Проверка канала C+CLub']);return back()->with('success','Тестовое событие сохранено. Очередь обработает его в течение минуты.');}
    public function toggle(Request $r,TelegramChannel $channel){abort_unless($r->user()->active&&ClubAccess::allClubs($r->user()),403);$channel->enabled=!$channel->enabled;$channel->save();return back();}
    public function retry(Request $r,int $id){abort_unless($r->user()->active&&ClubAccess::allClubs($r->user()),403);$delivery=DB::table('deliveries')->find($id);abort_unless($delivery,404);abort_unless($delivery->status==='failed',422);DB::table('deliveries')->where('id',$id)->update(['status'=>'pending','attempts'=>0,'next_attempt_at'=>null]);return back()->with('success','Повтор запланирован. Возможен дубль, если Telegram принял предыдущую попытку без ответа.');}
}
