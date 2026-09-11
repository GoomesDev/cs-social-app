<?php

namespace App\Http\Controllers;

use App\Models\Users;
use App\Services\SteamOpenId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SteamAuthController extends Controller
{
    private const COOKIE = 'steam_mobile_binding';

    public function redirect(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'redirect_uri' => ['required', 'string', Rule::in(config('steam_auth.redirect_uris'))],
            'state' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{32,128}\z/'],
            'code_challenge' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
            'code_challenge_method' => ['required', Rule::in(['S256'])],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_request', 'errors' => $validator->errors()], 422);
        }
        $input = $validator->validated();
        $callback = config('steam_auth.callback_url');
        $origin = preg_replace('~/auth/steam/callback\z~', '', $callback);
        if ($callback !== $origin.'/auth/steam/callback'
            || $request->getSchemeAndHttpHost() !== $origin
            || (! str_starts_with($origin, 'https://') && ! app()->environment('local', 'testing'))) {
            return response()->json(['error' => 'invalid_origin'], 400);
        }

        $state = bin2hex(random_bytes(32));
        $binding = bin2hex(random_bytes(32));
        $returnTo = $callback.'?state='.$state;
        DB::table('steam_auth_attempts')->insert([
            'state_hash' => hash('sha256', $state),
            'browser_hash' => hash('sha256', $binding),
            'client_state' => $input['state'],
            'redirect_uri' => $input['redirect_uri'],
            'code_challenge' => $input['code_challenge'],
            'callback_url' => $callback,
            'expires_at' => now()->addSeconds(config('steam_auth.attempt_seconds')),
        ]);
        $params = [
            'openid.ns' => SteamOpenId::NS,
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $returnTo,
            'openid.realm' => $origin.'/',
            'openid.identity' => SteamOpenId::NS.'/identifier_select',
            'openid.claimed_id' => SteamOpenId::NS.'/identifier_select',
        ];

        return redirect()->away(SteamOpenId::ENDPOINT.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986))
            ->withCookie(cookie(self::COOKIE, $binding, (int) ceil(config('steam_auth.attempt_seconds') / 60),
                '/auth/steam', null, $request->isSecure(), true, false, 'lax'));
    }

    public function callback(Request $request, SteamOpenId $openid)
    {
        try {
            $parameters = $openid->parameters($request);
        } catch (Throwable) {
            return response()->json(['error' => 'invalid_attempt'], 401);
        }
        $state = $parameters['state'] ?? '';
        $binding = $request->cookie(self::COOKIE);
        if (! preg_match('/\A[a-f0-9]{64}\z/', $state) || ! is_string($binding)
            || $request->url() !== config('steam_auth.callback_url')) {
            return response()->json(['error' => 'invalid_attempt'], 401);
        }
        $attempt = DB::table('steam_auth_attempts')->where('state_hash', hash('sha256', $state))->first();
        if (! $attempt || ! hash_equals($attempt->browser_hash, hash('sha256', $binding))
            || ! in_array($attempt->redirect_uri, config('steam_auth.redirect_uris'), true)
            || $attempt->callback_used_at !== null) {
            return response()->json(['error' => 'invalid_attempt'], 401);
        }
        // A conditional write is the one-time claim, including under concurrent callbacks.
        $claimed = DB::table('steam_auth_attempts')->where('state_hash', $attempt->state_hash)
            ->whereNull('callback_used_at')->where('expires_at', '>', now())
            ->update(['callback_used_at' => now()]);
        if ($claimed !== 1) {
            return $this->backToApp($attempt, ['error' => 'attempt_expired']);
        }
        if (($parameters['openid.mode'] ?? '') === 'cancel') {
            return $this->backToApp($attempt, ['error' => 'access_denied']);
        }

        try {
            $steamId = $openid->verify($parameters, $attempt->callback_url.'?state='.$state);
            $profileUrl = rtrim(config('services.steam.api_base'), '/').'/'.ltrim(config('services.steam.api_player_summary'), '/');
            if (! preg_match('~\Ahttps://api\.steampowered\.com/ISteamUser/GetPlayerSummaries/v(?:0002|2)/?\z~', $profileUrl)) {
                throw new \RuntimeException('Invalid profile endpoint configuration');
            }
            $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->get($profileUrl, [
                'key' => config('services.steam.api_key'), 'steamids' => $steamId,
            ]);
            $player = $response->json('response.players.0');
            if (! $response->successful() || ! is_array($player) || ($player['steamid'] ?? null) !== $steamId) {
                throw new \RuntimeException('Profile unavailable');
            }
            $code = bin2hex(random_bytes(32));
            DB::transaction(function () use ($attempt, $parameters, $steamId, $player, $code) {
                if (now()->greaterThanOrEqualTo($attempt->expires_at)) {
                    throw new \RuntimeException('Attempt expired');
                }
                // Unique across attempts: accepting an assertion nonce twice is forbidden.
                DB::table('steam_auth_attempts')->where('state_hash', $attempt->state_hash)
                    ->update(['nonce_hash' => hash('sha256', $parameters['openid.response_nonce'])]);
                $user = Users::firstOrNew(['steam_id' => $steamId]);
                if ($user->exists && ! $user->is_active) {
                    throw new \RuntimeException('Account unavailable');
                }
                $name = is_string($player['personaname'] ?? null) ? $player['personaname'] : 'Steam User';
                $user->fill([
                    'display_name' => Str::limit($name, 100, ''),
                    'username' => Str::limit($name, 50, ''),
                    'avatar' => $player['avatarfull'] ?? null,
                    'profile_url' => $player['profileurl'] ?? null,
                    'last_sync_at' => now(), 'is_active' => true,
                ])->save();
                DB::table('steam_auth_attempts')->where('state_hash', $attempt->state_hash)->update([
                    'user_id' => $user->id,
                    'code_hash' => hash('sha256', $code),
                    'code_expires_at' => now()->addSeconds(config('steam_auth.code_seconds')),
                ]);
            });

            return $this->backToApp($attempt, ['code' => $code]);
        } catch (Throwable) {
            return $this->backToApp($attempt, ['error' => 'authentication_failed']);
        }
    }

    public function exchange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'code_verifier' => ['required', 'string', 'regex:/\A[A-Za-z0-9._~-]{43,128}\z/'],
            'redirect_uri' => ['required', 'string', Rule::in(config('steam_auth.redirect_uris'))],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_request', 'errors' => $validator->errors()], 422);
        }
        $input = $validator->validated();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $input['code_verifier'], true)), '+/', '-_'), '=');
        $result = DB::transaction(function () use ($input, $challenge) {
            $query = DB::table('steam_auth_attempts')->where('code_hash', hash('sha256', $input['code']));
            $attempt = (clone $query)->whereNull('consumed_at')->where('code_expires_at', '>', now())
                ->lockForUpdate()->first();
            if (! $attempt || ! hash_equals($attempt->code_challenge, $challenge)
                || $attempt->redirect_uri !== $input['redirect_uri']) {
                return null;
            }
            $user = Users::whereKey($attempt->user_id)->where('is_active', true)->first();
            if (! $user) {
                return null;
            }
            // CAS also protects engines where SELECT FOR UPDATE is not available.
            if ($query->whereNull('consumed_at')->where('code_expires_at', '>', now())
                ->update(['consumed_at' => now()]) !== 1) {
                return null;
            }
            $expiresAt = now()->addMinutes(config('steam_auth.token_minutes'));
            $token = $user->createToken('mobile', ['*'], $expiresAt);

            return [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toIso8601String(),
                'user' => $user->only(['id', 'steam_id', 'display_name', 'username', 'avatar', 'profile_url', 'is_active']),
            ];
        });

        return $result === null
            ? response()->json(['error' => 'invalid_grant'], 401)
            : response()->json($result);
    }

    private function backToApp(object $attempt, array $parameters)
    {
        return redirect()->away($attempt->redirect_uri.'?'.http_build_query(
            [...$parameters, 'state' => $attempt->client_state], '', '&', PHP_QUERY_RFC3986
        ))->withCookie(cookie()->forget(self::COOKIE, '/auth/steam'));
    }
}
