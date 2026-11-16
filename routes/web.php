<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['service' => config('app.name'), 'status' => 'running']);
});

Route::get('/health', HealthController::class)->name('health')->withoutMiddleware('web');
