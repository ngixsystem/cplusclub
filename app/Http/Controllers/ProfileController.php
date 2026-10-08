<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Storage};
use Inertia\Inertia;

class ProfileController extends Controller
{
    private function user(Request $request) {
        abort_unless($request->user()->active,403);
        return $request->user();
    }
    public function index(Request $request) {
        $user=$this->user($request);
        return Inertia::render('Profile',['profile'=>$user->only('name','email')]);
    }
    public function avatar(Request $request) {
        $user=$this->user($request);
        $request->validate(['avatar'=>'required|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096']);
        $path=$request->file('avatar')->store('avatars','local');
        $old=$user->avatar_path;
        try {$user->avatar_path=$path;$user->save();}
        catch(\Throwable $e){Storage::disk('local')->delete($path);throw $e;}
        if($old) Storage::disk('local')->delete($old);
        return back()->with('success','Аватарка обновлена.');
    }
    public function image(Request $request) {
        $user=$this->user($request);$disk=Storage::disk('local');
        abort_unless($user->avatar_path && $disk->exists($user->avatar_path),404);
        return $disk->response($user->avatar_path,null,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'],'inline');
    }
    public function removeAvatar(Request $request) {
        $user=$this->user($request);$old=$user->avatar_path;$user->avatar_path=null;$user->save();
        if($old) Storage::disk('local')->delete($old);
        return back()->with('success','Аватарка удалена.');
    }
    public function password(Request $request) {
        $user=$this->user($request);
        $data=$request->validate(['current_password'=>'required|string|current_password','password'=>'required|string|min:6|max:72|confirmed|different:current_password']);
        $user->password=$data['password'];$user->remember_token=null;$user->save();
        Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();
        return redirect('/login');
    }
}
