<?php
use Illuminate\Support\Facades\Route;
Route::post('/v1/telemetry',[\App\Http\Controllers\TelemetryController::class,'ingest'])->middleware('throttle:agent');
Route::get('/v1/latency/targets',[\App\Http\Controllers\LatencyController::class,'targets'])->middleware('throttle:agent');
Route::post('/v1/latency',[\App\Http\Controllers\LatencyController::class,'ingest'])->middleware('throttle:agent');
Route::post('/v1/local-version',[\App\Http\Controllers\UpdatesController::class,'local'])->middleware('throttle:agent');

\Illuminate\Support\Facades\Route::post('/connection-requests', [\App\Http\Controllers\ConnectionRequestController::class,'store'])->middleware('throttle:leads');
Route::post('/v1/remote/enroll',[\App\Http\Controllers\RemoteController::class,'enroll'])->middleware('throttle:10,1');

// Read-only external API (token per consumer, one club). Docs: docs/external-api.md
Route::prefix('ext/v1')->group(function () {
    Route::get('/me', [\App\Http\Controllers\ExternalApiController::class, 'me'])->middleware(\App\Http\Middleware\ExternalApi::class);
    Route::get('/pcs', [\App\Http\Controllers\ExternalApiController::class, 'pcs'])->middleware(\App\Http\Middleware\ExternalApi::class.':pcs');
    Route::get('/shifts', [\App\Http\Controllers\ExternalApiController::class, 'shifts'])->middleware(\App\Http\Middleware\ExternalApi::class.':shifts');
});
