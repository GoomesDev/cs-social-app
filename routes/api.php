<?php

use App\Http\Controllers\ActivityFeedController;
use App\Http\Controllers\FriendsController;
use App\Http\Controllers\GroupsController;
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

Route::get('/feed', [ActivityFeedController::class, 'index'])
    ->middleware(['auth:sanctum', 'throttle:feed']);

Route::middleware(['auth:sanctum', 'throttle:groups'])->prefix('groups')->group(function () {
    Route::get('/', [GroupsController::class, 'index']);
    Route::post('/', [GroupsController::class, 'store']);
    Route::get('/candidates', [GroupsController::class, 'candidates']);
    Route::get('/{group}', [GroupsController::class, 'show']);
    Route::patch('/{group}', [GroupsController::class, 'update']);
    Route::post('/{group}/members', [GroupsController::class, 'addMember']);
    Route::delete('/{group}/members/{user}', [GroupsController::class, 'removeMember']);
    Route::delete('/{group}', [GroupsController::class, 'destroy']);
});
