<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AuditAction {
    public function handle(Request $request,Closure $next){$response=$next($request);if($request->user()&&!$request->isMethodSafe()&&$response->getStatusCode()<400&&!$request->is('login','logout'))DB::table('audit_logs')->insert(['actor_id'=>$request->user()->id,'action'=>$request->method(),'subject'=>substr($request->path(),0,100),'created_at'=>now()]);return $response;}
}
