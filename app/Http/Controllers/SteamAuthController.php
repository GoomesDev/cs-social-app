<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Users;

class SteamAuthController extends Controller
{
    public function redirect()
    {
        $params = [
            'openid.ns'         => 'http://specs.openid.net/auth/2.0',
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => url('/auth/steam/callback'),
            'openid.realm'      => url('/'),
            'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ];

        return redirect(
            'https://steamcommunity.com/openid/login?' . http_build_query($params)
        );
    }

    public function callback(Request $request)
    {
        if ($request->get('openid_mode') !== 'id_res') {
            return response()->json(['error' => 'Login inválido'], 401);
        }

        $params = $request->all();
        $params['openid.mode'] = 'check_authentication';

        $response = Http::asForm()
            ->post('https://steamcommunity.com/openid/login', $params);

        preg_match(
            '/https:\/\/steamcommunity.com\/openid\/id\/(\d+)/',
            $request->get('openid_claimed_id'),
            $matches
        );

        $steamId = $matches[1] ?? null;

        if (!$steamId) {
            return response()->json(['error' => 'SteamID não encontrado'], 401);
        }

        $user = Users::firstOrCreate(
            ['steam_id' => $steamId],
            ['name' => 'Steam User']
        );

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'steam_id' => $steamId
        ]);
    }
}