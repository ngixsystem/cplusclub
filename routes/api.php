<?php
use Illuminate\Support\Facades\Route;
Route::post('/v1/telemetry',[\App\Http\Controllers\TelemetryController::class,'ingest'])->middleware('throttle:agent');
Route::get('/v1/latency/targets',[\App\Http\Controllers\LatencyController::class,'targets'])->middleware('throttle:agent');
Route::post('/v1/latency',[\App\Http\Controllers\LatencyController::class,'ingest'])->middleware('throttle:agent');
Route::post('/v1/local-version',[\App\Http\Controllers\UpdatesController::class,'local'])->middleware('throttle:agent');

\Illuminate\Support\Facades\Route::post('/connection-requests', [\App\Http\Controllers\ConnectionRequestController::class,'store'])->middleware('throttle:leads');
