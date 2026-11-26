<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [HealthController::class, 'check'])->name('healthz');
Route::get('/', [HealthController::class, 'spa'])->name('home');
