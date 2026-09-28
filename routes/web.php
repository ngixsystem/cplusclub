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
    Route::post('/logout', function (Request $request) {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect('/login');
    });
    Route::get('/', [ServiceController::class, 'index']);
    Route::get('/tickets/{ticket}', [ServiceController::class, 'show'])->whereNumber('ticket');
    Route::post('/tickets/{ticket}/transition', [ServiceController::class, 'transition']);
    Route::post('/tickets/{ticket}/assign', [ServiceController::class, 'assign']);
    Route::post('/clubs', [ServiceController::class, 'club']);
    Route::post('/equipment', [ServiceController::class, 'equipment']);
    Route::post('/tickets', [ServiceController::class, 'ticket']);
    Route::get('/{section}', [ServiceController::class, 'index'])->whereIn('section', ['clubs','equipment','tickets']);
});
