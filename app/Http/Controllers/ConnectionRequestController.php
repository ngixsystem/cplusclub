<?php
namespace App\Http\Controllers;
use App\Models\ConnectionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
class ConnectionRequestController extends Controller {
    public function store(Request $r){
        $d=$r->validate(['request_id'=>'required|uuid','name'=>'required|string|max:100','club'=>'required|string|max:150','contact'=>'required|string|min:5|max:200','pc_count'=>'required|integer|min:1|max:1000','plan'=>['required',Rule::in(['standard','priority','custom'])],'message'=>'nullable|string|max:2000','consent'=>'accepted','website'=>'nullable|string|max:0']);
        unset($d['consent'],$d['website']);
        ConnectionRequest::firstOrCreate(['request_id'=>$d['request_id']],$d);
        return response()->json(['message'=>'Запрос сохранён. Команда C+club свяжется с вами по указанному контакту.'],201);
    }
    public function index(Request $r){
        Gate::authorize('viewAny',ConnectionRequest::class);
        $status=$r->validate(['status'=>['nullable',Rule::in(['new','contacted','closed'])]])['status']??'';
        return Inertia::render('ConnectionRequests',['leads'=>ConnectionRequest::when($status,fn($q)=>$q->where('status',$status))->orderByDesc('id')->paginate(20)->withQueryString(),'status'=>$status,'newCount'=>ConnectionRequest::where('status','new')->count()]);
    }
    public function update(Request $r,ConnectionRequest $lead){
        Gate::authorize('update',$lead);$d=$r->validate(['status'=>['required',Rule::in(['new','contacted','closed'])]]);
        $lead->status=$d['status'];$lead->save();return back()->with('success','Статус запроса обновлён.');
    }
}
