<?php

use Illuminate\Support\Facades\{Route, Auth, DB, Redis};
use Illuminate\Http\Request;
use App\Http\Controllers\ServiceController;
use Inertia\Inertia;

Route::get('/health/live', fn () => response()->json(['status' => 'alive']));
Route::get('/health/ready', function () {
    try { DB::select('SELECT 1'); Redis::ping(); }
    catch (Throwable) { return response()->json(['status' => 'unavailable'], 503); }
    return response()->json(['status' => 'ready']);
});
Route::get('/login', fn () => Inertia::render('Login'))->name('login');
Route::post('/login', [ServiceController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('auth')->group(function () {
    Route::get('/clubs/{club}/dashboard', [\App\Http\Controllers\IcafeController::class, 'data'])->middleware('throttle:60,1');
    Route::get('/clubs/{club}/dashboard/shifts/{shift}', [\App\Http\Controllers\IcafeController::class, 'shift'])->where('shift', '-?[0-9]+')->middleware('throttle:60,1');
    Route::post('/clubs/{club}/icafe', [\App\Http\Controllers\IcafeController::class, 'save']);
    Route::get('/users',[\App\Http\Controllers\AdminController::class,'index']);
    Route::post('/users',[\App\Http\Controllers\AdminController::class,'store']);
    Route::post('/users/{user}',[\App\Http\Controllers\AdminController::class,'update']);
    Route::delete('/users/{user}',[\App\Http\Controllers\AdminController::class,'destroy']);
    Route::get('/equipment/{equipment}',[\App\Http\Controllers\AssetController::class,'equipment']);
    Route::post('/equipment/{equipment}',[\App\Http\Controllers\AssetController::class,'updateEquipment']);
    Route::get('/clubs/{club}',[\App\Http\Controllers\AssetController::class,'club']);
    Route::post('/clubs/{club}',[\App\Http\Controllers\AssetController::class,'updateClub']);
    Route::get('/reports',[\App\Http\Controllers\ReportController::class,'index']);
    Route::get('/reports/export',[\App\Http\Controllers\ReportController::class,'export']);
    Route::post('/tickets/{ticket}/work',[\App\Http\Controllers\ReportController::class,'log']);
    Route::get('/updates',[\App\Http\Controllers\UpdatesController::class,'index']);
    Route::post('/updates/subscribe',[\App\Http\Controllers\UpdatesController::class,'subscribe']);
    Route::post('/updates/verify',[\App\Http\Controllers\UpdatesController::class,'verify']);
    Route::get('/integrations',[\App\Http\Controllers\IntegrationController::class,'index']);
    Route::post('/integrations/channels',[\App\Http\Controllers\IntegrationController::class,'store']);
    Route::post('/integrations/channels/{channel}/test',[\App\Http\Controllers\IntegrationController::class,'test']);
    Route::post('/integrations/channels/{channel}/toggle',[\App\Http\Controllers\IntegrationController::class,'toggle']);
    Route::post('/integrations/deliveries/{id}/retry',[\App\Http\Controllers\IntegrationController::class,'retry']);
    Route::get('/monitoring',[\App\Http\Controllers\MonitoringController::class,'index']);
    Route::get('/monitoring/clubs/{club}',[\App\Http\Controllers\MonitoringController::class,'live'])->middleware('throttle:60,1');
    Route::post('/monitoring/clubs/{club}/import',[\App\Http\Controllers\MonitoringController::class,'import']);
    Route::post('/monitoring/clubs/{club}/template',[\App\Http\Controllers\MonitoringController::class,'template']);
    Route::post('/agents',[\App\Http\Controllers\MonitoringController::class,'register']);
    Route::post('/agents/{id}/revoke',[\App\Http\Controllers\MonitoringController::class,'revoke']);
    Route::post('/monitoring/rules',[\App\Http\Controllers\MonitoringController::class,'rule']);
    Route::post('/monitoring/maintenance',[\App\Http\Controllers\MonitoringController::class,'maintenance']);
    Route::get('/visits', [\App\Http\Controllers\VisitController::class,'index']);
    Route::post('/visits', [\App\Http\Controllers\VisitController::class,'store']);
    Route::get('/visits/{visit}', [\App\Http\Controllers\VisitController::class,'show']);
    Route::post('/visits/{visit}/complete', [\App\Http\Controllers\VisitController::class,'complete']);
    Route::post('/inspections/{inspection}', [\App\Http\Controllers\VisitController::class,'inspect']);
    Route::post('/inspections/{inspection}/approve', [\App\Http\Controllers\VisitController::class,'approve']);
    Route::post('/inspections/{inspection}/ticket', [\App\Http\Controllers\VisitController::class,'issue']);
    Route::post('/attachments', [\App\Http\Controllers\AttachmentController::class,'store']);
    Route::get('/attachments/{attachment}', [\App\Http\Controllers\AttachmentController::class,'download']);
    Route::get('/attachments/{attachment}/preview', [\App\Http\Controllers\AttachmentController::class,'preview']);
    Route::post('/logout', function (Request $request) {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect('/login');
    });
    Route::get('/', [ServiceController::class, 'index']);
    Route::get('/tickets/{ticket}', [ServiceController::class, 'show'])->whereNumber('ticket');
    Route::post('/tickets/{ticket}/transition', [ServiceController::class, 'transition']);
    Route::post('/tickets/{ticket}/proposal', [\App\Http\Controllers\TicketApprovalController::class, 'propose']);
    Route::post('/tickets/{ticket}/decision', [\App\Http\Controllers\TicketApprovalController::class, 'decide']);
    Route::post('/tickets/{ticket}/completion', [\App\Http\Controllers\TicketApprovalController::class, 'complete']);
    Route::post('/tickets/{ticket}/assign', [ServiceController::class, 'assign']);
    Route::post('/clubs', [ServiceController::class, 'club']);
    Route::post('/equipment', [ServiceController::class, 'equipment']);
    Route::post('/tickets', [ServiceController::class, 'ticket']);
    Route::get('/{section}', [ServiceController::class, 'index'])->whereIn('section', ['clubs','equipment','tickets']);
});
