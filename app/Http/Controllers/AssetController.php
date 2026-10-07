<?php
namespace App\Http\Controllers;
use App\Models\{Club,Equipment,Attachment,User};
use App\Domain\Access\ClubAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate,DB};
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class AssetController extends Controller {
    public function equipment(Equipment $equipment){Gate::authorize('view',$equipment);return Inertia::render('Equipment',['equipment'=>$equipment->load('club'),'attachments'=>Attachment::where('equipment_id',$equipment->id)->get(),'tickets'=>\App\Models\Ticket::where('equipment_id',$equipment->id)->orderByDesc('id')->paginate(20,['id','description','status'],'tickets_page')->withQueryString(),'canEdit'=>Gate::allows('update',$equipment)]);}
    public function updateEquipment(Request $r,Equipment $equipment){Gate::authorize('update',$equipment);$d=$r->validate(['name'=>'required|string|max:255','zone'=>'nullable|string|max:255','workstation_number'=>['nullable','string','max:80',Rule::unique('equipment')->where('club_id',$equipment->club_id)->ignore($equipment->id)],'cpu'=>'nullable|string|max:255','gpu'=>'nullable|string|max:255','ram_mb'=>'nullable|integer|min:0|max:4194304','disks'=>'nullable|string|max:2000','serial_number'=>'nullable|string|max:255','inventory_number'=>'nullable|string|max:255','ip'=>'nullable|ip','mac'=>'nullable|mac_address','status'=>['required',Rule::in(['active','maintenance','retired'])]]);$equipment->update($d);return back()->with('success','Карточка обновлена. История связана с постоянным ID устройства.');}
    public function club(Club $club){Gate::authorize('view',$club);return Inertia::render('Club',['club'=>$club,'canEdit'=>Gate::allows('update',$club),'specialists'=>Gate::allows('update',$club)?User::where('role','specialist')->where('active',true)->get(['id','name']):[]]);}
    public function updateClub(Request $r,Club $club){Gate::authorize('update',$club);$d=$r->validate(['name'=>'required|string|max:255','address'=>'required|string|max:2000','timezone'=>'required|timezone','contacts'=>'nullable|string|max:2000','support_hours'=>'required|string|max:255','status'=>['required',Rule::in(['active','paused','ended'])],'notes'=>'nullable|string|max:10000','specialist_id'=>'nullable|integer|exists:users,id']);if(!empty($d['specialist_id']))abort_unless(User::whereKey($d['specialist_id'])->where('role','specialist')->where('active',true)->exists(),422);DB::transaction(function()use($club,$d){$club->update($d);if($club->specialist_id)$club->members()->syncWithoutDetaching([$club->specialist_id]);});return back()->with('success','Данные клуба сохранены.');}
}
