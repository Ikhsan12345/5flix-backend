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
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

// Video routes - dengan proteksi admin untuk CUD operations
// Menggunakan apiResource untuk CRUD dasar
Route::apiResource('videos', VideoController::class)
    ->middleware('auth:sanctum');  // Proteksi untuk akses video