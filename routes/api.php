<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Rutas públicas (sin token)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Rutas protegidas: exigen "Authorization: Bearer <token>"
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // A partir de aquí van los endpoints de dominio (competitions,
    // categories, registrations, matches, rankings, chat-messages...)
    // según se vayan construyendo los controladores correspondientes.
});

