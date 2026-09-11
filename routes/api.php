<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ChatMessageController;
use App\Http\Controllers\Api\CompetitionController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MatchdayPairingController;
use App\Http\Controllers\Api\MatchSetController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PairController;
use App\Http\Controllers\Api\PhaseController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RoundRobinController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Rutas públicas (sin token)
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/google', [AuthController::class, 'loginWithGoogle'])->middleware('throttle:social-login');
Route::post('/auth/apple', [AuthController::class, 'loginWithApple'])->middleware('throttle:social-login');
Route::post('/email/verify', [AuthController::class, 'verifyEmail'])->middleware('throttle:email-verify');
Route::post('/email/resend', [AuthController::class, 'resendVerificationEmail'])->middleware('throttle:email-verify');

// Rutas protegidas: exigen "Authorization: Bearer <token>"
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'update']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me/registrations', [MeController::class, 'registrations']);
    Route::get('/me/competitions', [MeController::class, 'competitions']);

    Route::get('/users', [UserController::class, 'index']);

    // A partir de aquí van los endpoints de dominio (competitions,
    // categories, registrations, matches, rankings, chat-messages...)
    // según se vayan construyendo los controladores correspondientes.
    Route::apiResource('pairs', PairController::class)->only(['index', 'store', 'show']);

    Route::get('/invites/{token}', [InviteController::class, 'show'])->middleware('throttle:invite');
    Route::get('/invites/{token}/categories', [InviteController::class, 'categories'])->middleware('throttle:invite');

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
