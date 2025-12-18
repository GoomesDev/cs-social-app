<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SteamAuthController;

Route::get('/auth/steam/redirect', [SteamAuthController::class, 'redirect']);
Route::get('/auth/steam/callback', [SteamAuthController::class, 'callback']);