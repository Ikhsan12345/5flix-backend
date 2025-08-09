<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\AuthController;

// User info - perlu login
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Auth routes - tidak perlu login
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// Video routes
// Public routes (no authentication needed)
Route::get('videos', [VideoController::class, 'index']);
Route::get('videos/{id}', [VideoController::class, 'show']);

// Protected routes (authentication + admin check handled in controller)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('videos', [VideoController::class, 'store']);

    // Original PUT/PATCH routes
    Route::put('videos/{id}', [VideoController::class, 'update']);
    Route::patch('videos/{id}', [VideoController::class, 'update']);

    // SOLUSI ALTERNATIF: POST route untuk form-data updates
    // Gunakan ini jika PUT dengan form-data tidak berfungsi
    Route::post('videos/{id}/update', [VideoController::class, 'update']);

    Route::delete('videos/{id}', [VideoController::class, 'destroy']);

    // Debug route - hapus setelah selesai debugging
    Route::any('videos/{id}/debug', [VideoController::class, 'debugUpdate']);
});