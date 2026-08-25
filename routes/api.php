<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChatMessageController;
use App\Http\Controllers\Api\CompetitionController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MatchdayPairingController;
use App\Http\Controllers\Api\MatchSetController;
use App\Http\Controllers\Api\PhaseController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RoundRobinController;
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
    Route::apiResource('competitions', CompetitionController::class);
    Route::apiResource('competitions.categories', CategoryController::class)->shallow();
    Route::apiResource('categories.registrations', RegistrationController::class)->shallow();
    Route::apiResource('categories.phases', PhaseController::class)->shallow();
    Route::apiResource('phases.matches', MatchController::class)->shallow();
    Route::apiResource('phases.matchday-pairings', MatchdayPairingController::class)->shallow();
    Route::post('/categories/{category}/round-robin', [RoundRobinController::class, 'generate']);
    Route::apiResource('matches.sets', MatchSetController::class)->shallow();

    Route::get('/categories/{category}/rankings', [RankingController::class, 'index']);
    Route::post('/categories/{category}/rankings/recalculate', [RankingController::class, 'recalculate']);

    Route::apiResource('competitions.chat-messages', ChatMessageController::class)
        ->shallow()
        ->only(['index', 'store', 'destroy']);
});
