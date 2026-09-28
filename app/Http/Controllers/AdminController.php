<?php
namespace App\Http\Controllers;
use App\Models\{Club,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class AdminController extends Controller {
    private function owner(Request $r):void {abort_unless($r->user()->active&&$r->user()->role==='owner',403);}
    public function index(Request $r){$this->owner($r);return Inertia::render('Users',['users'=>User::with('clubs:id,name')->orderBy('name')->paginate(30),'clubs'=>Club::get(['id','name'])]);}
    public function store(Request $r){$this->owner($r);$d=$r->validate(['name'=>'required|string|max:100','email'=>'required|email|max:255|unique:users,email','password'=>'required|string|min:14|max:200','role'=>['required',Rule::in(['owner','lead','specialist','representative'])],'club_ids'=>'array|max:1000','club_ids.*'=>'integer|exists:clubs,id']);DB::transaction(function()use($d){$u=new User();$u->name=$d['name'];$u->email=$d['email'];$u->password=Hash::make($d['password']);$u->role=$d['role'];$u->save();$u->clubs()->sync($d['club_ids']??[]);});return back()->with('success','Пользователь создан. Передайте пароль безопасным каналом.');}
    public function update(Request $r,User $user){$this->owner($r);$d=$r->validate(['club_ids'=>'required|array|max:1000','club_ids.*'=>'integer|exists:clubs,id','active'=>'required|boolean']);abort_if($user->id===$r->user()->id&&!$d['active'],422,'Нельзя отключить свой аккаунт.');DB::transaction(function()use($user,$d){$user->active=$d['active'];$user->save();$user->clubs()->sync($d['club_ids']);});return back()->with('success','Доступ обновлён.');}
}
