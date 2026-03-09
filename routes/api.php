<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;

Route::get('/normal', [ApiController::class, 'normal'])->name('api.normal');
Route::get('/slow', [ApiController::class, 'slow'])->name('api.slow');
Route::get('/error', [ApiController::class, 'error'])->name('api.error');
Route::get('/random', [ApiController::class, 'random'])->name('api.random');
Route::get('/db', [ApiController::class, 'db'])->name('api.db');
Route::post('/validate', [ApiController::class, 'validate'])->name('api.validate');
Route::get('/metrics', [ApiController::class, 'metrics'])->name('api.metrics');