<?php

use App\Http\Controllers\FriendsController;
use App\Http\Controllers\SteamAuthController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\UserStatSnapshotsController;
use App\Http\Middleware\SteamAuthResponse;
use Illuminate\Support\Facades\Route;

Route::get('/get-profile/{userId}', [UsersController::class, 'getProfile']);

Route::prefix('player-stats')->group(function () {
    Route::get('/{userId}', [UserStatSnapshotsController::class, 'getSnapshotByUser']);
    Route::get('weekly-snapshot/{userId}/{startDate?}', [UserStatSnapshotsController::class, 'getWeeklyStats']);
    Route::get('daily-snapshot/{userId}/{date?}', [UserStatSnapshotsController::class, 'getDailyStats']);
});

Route::post('/auth/steam/exchange', [SteamAuthController::class, 'exchange'])
    ->middleware([SteamAuthResponse::class, 'throttle:steam-auth-exchange']);

Route::get('/friends', [FriendsController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:friends']);
Route::post('/friends/sync', [FriendsController::class, 'sync'])
    ->middleware(['auth:sanctum', 'throttle:friends']);
