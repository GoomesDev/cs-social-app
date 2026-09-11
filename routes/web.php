<?php

use App\Http\Controllers\SteamAuthController;
use App\Http\Middleware\SteamAuthResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// An encrypted, HttpOnly binding cookie is used instead of a Laravel login session.
Route::middleware([SteamAuthResponse::class, 'throttle:steam-auth-browser'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->group(function () {
        Route::get('/auth/steam/redirect', [SteamAuthController::class, 'redirect']);
        Route::get('/auth/steam/callback', [SteamAuthController::class, 'callback']);
    });
