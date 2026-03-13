<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GestionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Rutas protegidas
Route::middleware('auth')->group(function () {
    Route::get('/', [GestionController::class, 'index']);
    Route::post('/upload', [GestionController::class, 'upload']);
    Route::get('/process/{batchId}', [GestionController::class, 'process']);
    Route::get('/status/{batchId}', [GestionController::class, 'status']);
    Route::get('/logs', [GestionController::class, 'listLogs']);
    Route::get('/logs/{filename}', [GestionController::class, 'viewLog']);

    // Usuarios
    Route::get('/usuarios', [UserController::class, 'index']);
    Route::post('/usuarios', [UserController::class, 'store']);
    Route::delete('/usuarios/{user}', [UserController::class, 'destroy']);
});
