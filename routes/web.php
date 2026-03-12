<?php

use App\Http\Controllers\GestionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GestionController::class, 'index']);
Route::post('/upload', [GestionController::class, 'upload']);
Route::get('/process/{batchId}', [GestionController::class, 'process']);
Route::get('/status/{batchId}', [GestionController::class, 'status']);
Route::get('/logs', [GestionController::class, 'listLogs']);
Route::get('/logs/{filename}', [GestionController::class, 'viewLog']);
