<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \App\Http\Middleware\SteamAuthResponse::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Auth exceptions may contain credentials in HTTP URLs or SQL bindings.
        $isSteamAuth = fn () => request()->is('auth/steam/redirect', 'auth/steam/callback', 'api/auth/steam/exchange');
        $exceptions->dontReportWhen(fn (\Throwable $exception) => $isSteamAuth());
        $exceptions->render(function (\Throwable $exception) use ($isSteamAuth) {
            if (! $isSteamAuth()) {
                return null;
            }
            $http = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
            $status = $http ? $exception->getStatusCode() : 503;

            return response()->json([
                'error' => $status === 429 ? 'too_many_requests' : 'authentication_unavailable',
            ], $status, array_merge($http ? $exception->getHeaders() : [], [
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
            ]));
        });
    })->create();
