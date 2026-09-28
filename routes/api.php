<?php
use Illuminate\Support\Facades\Route;
Route::post('/v1/telemetry',[\App\Http\Controllers\TelemetryController::class,'ingest'])->middleware('throttle:agent');
Route::post('/v1/local-version',[\App\Http\Controllers\UpdatesController::class,'local'])->middleware('throttle:agent');
