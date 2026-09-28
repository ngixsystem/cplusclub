<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
class RequestMetrics {
    public function handle(Request $request,Closure $next){$id=(string)Str::uuid();$start=hrtime(true);$response=$next($request);$response->headers->set('X-Correlation-ID',$id);Log::info('http.request',['correlation_id'=>$id,'method'=>$request->method(),'route'=>$request->route()?->uri(),'status'=>$response->getStatusCode(),'duration_ms'=>round((hrtime(true)-$start)/1e6,2)]);return $response;}
}
