<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\UserStatSnapshotsController;

Route::get('/get-profile/{userId}', [UsersController::class, 'getProfile']);

Route::prefix('player-stats')->group(function () {
    Route::get('/{userId}', [UserStatSnapshotsController::class, 'getSnapshotByUser']);
});