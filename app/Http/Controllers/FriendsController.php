<?php

namespace App\Http\Controllers;

use App\Services\SteamFriends;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class FriendsController extends Controller
{
    public function index(Request $request, SteamFriends $friends)
    {
        if (! $request->user()->is_active) {
            return response()->json(['error' => 'account_inactive'], 403);
        }

        try {
            return response()->json($friends->forUser($request->user()))
                ->header('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : '';
            if ($error === 'steam_friends_unavailable') {
                return response()->json([
                    'error' => $error,
                    'message' => 'A Steam não permitiu consultar seus amigos. Verifique a privacidade da lista e tente novamente.',
                ], 403);
            }

            return response()->json([
                'error' => $error === 'steam_not_configured' ? $error : 'steam_unavailable',
                'message' => 'Não foi possível consultar os amigos na Steam. Tente novamente mais tarde.',
            ], 503);
        }
    }

    public function sync(Request $request, SteamFriends $friends)
    {
        if (! $request->user()->is_active) {
            return response()->json(['error' => 'account_inactive'], 403);
        }

        try {
            return response()->json($friends->syncForUser($request->user()))
                ->header('Cache-Control', 'private, no-store');
        } catch (Throwable $exception) {
            $error = $exception instanceof RuntimeException ? $exception->getMessage() : '';
            if ($error === 'steam_friends_unavailable') {
                return response()->json([
                    'error' => $error,
                    'message' => 'A Steam não permitiu consultar seus amigos. Verifique a privacidade da lista e tente novamente.',
                ], 403);
            }

            return response()->json([
                'error' => $error === 'steam_not_configured' ? $error : 'friends_sync_failed',
                'message' => 'Não foi possível sincronizar os amigos. Tente novamente mais tarde.',
            ], 503);
        }
    }
}
