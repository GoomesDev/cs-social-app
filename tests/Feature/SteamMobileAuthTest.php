<?php

namespace Tests\Feature;

use App\Models\Users;
use App\Services\SteamOpenId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\IsolatedDatabaseTestCase;

class SteamMobileAuthTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    private const RETURN_URI = 'killfeedmobile://auth/callback';

    private const ID = '76561198000000000';

    private const VERIFIER = 'a-long-random-verifier-for-this-test-only-1234567890';

    private const CLIENT_STATE = 'client-state-for-this-test-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->fakeSteam();
    }

    private function fakeSteam(string $verification = 'true', bool $networkFailure = false, bool $discoveryValid = true, bool $profileSuccess = true): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        $endpoint = $discoveryValid ? SteamOpenId::ENDPOINT : 'https://attacker.invalid/openid';
        Http::fake([
            'https://steamcommunity.com/openid/id/*' => Http::response(
                '<xrds:XRDS xmlns:xrds="xri://$xrds" xmlns="xri://$xrd*($v*2.0)"><XRD><Service>'
                .'<Type>http://specs.openid.net/auth/2.0/signon</Type><URI>'.$endpoint.'</URI>'
                .'</Service></XRD></xrds:XRDS>', 200, ['Content-Type' => 'application/xrds+xml']),
            SteamOpenId::ENDPOINT => $networkFailure ? Http::failedConnection() : Http::response(
                'ns:'.SteamOpenId::NS."\nis_valid:".$verification."\n"),
            'https://api.steampowered.com/*' => Http::response(['response' => ['players' => [[
                'steamid' => self::ID, 'personaname' => 'Test Player',
                'avatarfull' => 'https://avatars.steamstatic.com/test.jpg',
                'profileurl' => 'https://steamcommunity.com/profiles/'.self::ID,
            ]]]], $profileSuccess ? 200 : 500),
        ]);
    }

    private function start(array $overrides = []): array
    {
        $response = $this->get('/auth/steam/redirect?'.http_build_query(array_replace([
            'redirect_uri' => self::RETURN_URI,
            'state' => self::CLIENT_STATE,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $overrides)));
        $response->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
        $cookie = $response->getCookie('steam_mobile_binding');
        $this->withCookie('steam_mobile_binding', $cookie->getValue());
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $openid);
        parse_str(parse_url($openid['openid_return_to'], PHP_URL_QUERY), $query);

        return ['return_to' => $openid['openid_return_to'], 'state' => $query['state']];
    }

    private function assertion(array $attempt, array $overrides = []): array
    {
        return array_replace([
            'state' => $attempt['state'],
            'openid.ns' => SteamOpenId::NS, 'openid.mode' => 'id_res',
            'openid.op_endpoint' => SteamOpenId::ENDPOINT,
            'openid.claimed_id' => 'https://steamcommunity.com/openid/id/'.self::ID,
            'openid.identity' => 'https://steamcommunity.com/openid/id/'.self::ID,
            'openid.return_to' => $attempt['return_to'],
            'openid.response_nonce' => now()->utc()->format('Y-m-d\TH:i:s\Z').bin2hex(random_bytes(8)),
            'openid.assoc_handle' => 'test-association',
            'openid.signed' => 'op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
            'openid.sig' => 'test-signature',
        ], $overrides);
    }

    private function completeCallback(array $assertion)
    {
        return $this->get('/auth/steam/callback?'.http_build_query($assertion));
    }

    private function code(): string
    {
        $response = $this->completeCallback($this->assertion($this->start()));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertSame(self::CLIENT_STATE, $query['state']);
        $this->assertArrayNotHasKey('token', $query);

        return $query['code'];
    }

    private function exchange(string $code, string $verifier = self::VERIFIER)
    {
        return $this->postJson('/api/auth/steam/exchange', [
            'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => self::RETURN_URI,
        ]);
    }

    public function test_valid_callback_returns_only_code_and_exchange_issues_expiring_sanctum_token(): void
    {
        $code = $this->code();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('steam_auth_attempts', ['code_hash' => $code]);
        $response = $this->exchange($code)->assertOk()->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.steam_id', self::ID)->assertJsonPath('user.display_name', 'Test Player');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertNotNull($token);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $token->expires_at->timestamp, 2);
        $this->assertInstanceOf(\Illuminate\Contracts\Auth\Authenticatable::class, $token->tokenable);
        $this->assertSame(Users::class, config('auth.providers.users.model'));
        // A test-only protected route proves the actual bearer guard works; no product endpoint added.
        Route::get('/_test/auth', fn () => response()->json(['id' => auth()->id()]))->middleware('auth:sanctum');
        $this->withToken($response->json('token'))->getJson('/_test/auth')->assertOk()->assertJsonPath('id', $token->tokenable_id);
        Http::assertSent(fn ($request) => $request->url() === SteamOpenId::ENDPOINT
            && $request['openid.mode'] === 'check_authentication'
            && $request['openid.claimed_id'] === 'https://steamcommunity.com/openid/id/'.self::ID
            && ! isset($request['state']));
    }

    public function test_steam_rejection_does_not_create_user_or_token(): void
    {
        $this->fakeSteam('false');
        $this->completeCallback($this->assertion($this->start()))
            ->assertRedirect(self::RETURN_URI.'?error=authentication_failed&state='.self::CLIENT_STATE);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, DB::table('steam_auth_attempts')->whereNotNull('code_hash')->count());
    }

    public function test_network_failure_does_not_authenticate_or_expose_secrets(): void
    {
        $this->fakeSteam(networkFailure: true);
        $response = $this->completeCallback($this->assertion($this->start()));
        $response->assertRedirect(self::RETURN_URI.'?error=authentication_failed&state='.self::CLIENT_STATE);
        $this->assertStringNotContainsString('test-key', $response->getContent());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_discovery_must_authorize_the_steam_endpoint(): void
    {
        $this->fakeSteam(discoveryValid: false);
        $this->completeCallback($this->assertion($this->start()))
            ->assertRedirect(self::RETURN_URI.'?error=authentication_failed&state='.self::CLIENT_STATE);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_reused_code_is_rejected(): void
    {
        $code = $this->code();
        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertUnauthorized()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->code();
        $this->travel(61)->seconds();
        $this->exchange($code)->assertUnauthorized()->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_verifier_is_rejected_without_consuming_code(): void
    {
        $code = $this->code();
        $this->exchange($code, str_repeat('b', 64))->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->exchange($code)->assertOk();
    }

    public function test_callback_requires_state_and_browser_binding(): void
    {
        $attempt = $this->start();
        $this->withCookie('steam_mobile_binding', 'wrong-browser');
        $this->completeCallback($this->assertion($attempt))->assertUnauthorized();
        $this->get('/auth/steam/callback?openid.mode=id_res')->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_attempt_is_rejected(): void
    {
        $attempt = $this->start();
        $this->travel(601)->seconds();
        $this->completeCallback($this->assertion($attempt))
            ->assertRedirect(self::RETURN_URI.'?error=attempt_expired&state='.self::CLIENT_STATE);
        Http::assertNothingSent();
    }

    public function test_callback_can_be_used_only_once(): void
    {
        $assertion = $this->assertion($this->start());
        $this->completeCallback($assertion)->assertRedirect();
        $this->completeCallback($assertion)->assertUnauthorized();
        $this->assertSame(1, DB::table('steam_auth_attempts')->whereNotNull('code_hash')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_cancel_returns_error_without_credentials(): void
    {
        $attempt = $this->start();
        $this->completeCallback(['state' => $attempt['state'], 'openid.mode' => 'cancel'])
            ->assertRedirect(self::RETURN_URI.'?error=access_denied&state='.self::CLIENT_STATE);
        $this->completeCallback($this->assertion($attempt))->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_arbitrary_return_destinations_and_plain_pkce_are_rejected(): void
    {
        foreach (['https://attacker.invalid', 'killfeedmobile://auth/callback/extra', 'killfeedmobile://auth/callback?redirect=evil'] as $uri) {
            $this->getJson('/auth/steam/redirect?'.http_build_query([
                'redirect_uri' => $uri, 'state' => self::CLIENT_STATE,
                'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
            ]))->assertUnprocessable()->assertJsonValidationErrors('redirect_uri');
        }
        $this->getJson('/auth/steam/redirect?'.http_build_query([
            'redirect_uri' => self::RETURN_URI, 'state' => self::CLIENT_STATE,
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'plain',
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('steam_auth_attempts', 0);
    }

    public function test_tampered_openid_assertions_are_rejected_before_external_requests(): void
    {
        foreach ([
            ['openid.return_to' => 'https://attacker.invalid/callback'],
            ['openid.claimed_id' => 'https://attacker.invalid/openid/id/'.self::ID],
            ['openid.identity' => 'https://steamcommunity.com/openid/id/76561198000000001'],
            ['openid.op_endpoint' => 'https://attacker.invalid/openid'],
            ['openid.ns' => 'http://specs.openid.net/auth/1.1'],
            ['openid.signed' => 'identity,claimed_id'],
            ['openid.response_nonce' => '2000-01-01T00:00:00Zold'],
        ] as $override) {
            $this->completeCallback($this->assertion($this->start(), $override))
                ->assertRedirect(self::RETURN_URI.'?error=authentication_failed&state='.self::CLIENT_STATE);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_openid_parameters_are_rejected(): void
    {
        $assertion = $this->assertion($this->start());
        $this->get('/auth/steam/callback?'.http_build_query($assertion).'&openid.mode=id_res')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_nonce_cannot_be_reused_across_attempts(): void
    {
        $nonce = now()->utc()->format('Y-m-d\TH:i:s\Z').'same-nonce';
        $this->completeCallback($this->assertion($this->start(), ['openid.response_nonce' => $nonce]))->assertRedirect();
        $this->completeCallback($this->assertion($this->start(), ['openid.response_nonce' => $nonce]))
            ->assertRedirect(self::RETURN_URI.'?error=authentication_failed&state='.self::CLIENT_STATE);
        $this->assertSame(1, DB::table('steam_auth_attempts')->whereNotNull('code_hash')->count());
    }

    public function test_exchange_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->exchange(str_repeat('a', 64))->assertUnauthorized();
        }
        $this->exchange(str_repeat('a', 64))->assertStatus(429);
    }

    public function test_profile_failure_does_not_create_user_or_token(): void
    {
        $this->fakeSteam(profileSuccess: false);
        $this->completeCallback($this->assertion($this->start()))
            ->assertRedirect(self::RETURN_URI.'?error=authentication_failed&state='.self::CLIENT_STATE);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_exchange_requires_the_original_return_destination(): void
    {
        $code = $this->code();
        $this->postJson('/api/auth/steam/exchange', [
            'code' => $code, 'code_verifier' => self::VERIFIER,
            'redirect_uri' => 'https://attacker.invalid',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_creation_failure_rolls_back_code_consumption_and_hides_details(): void
    {
        $code = $this->code();
        PersonalAccessToken::creating(function () {
            throw new \RuntimeException('secret-verifier-and-upstream-key');
        });
        try {
            $response = $this->exchange($code)->assertStatus(503)
                ->assertExactJson(['error' => 'authentication_unavailable']);
            $this->assertStringNotContainsString('secret-verifier', $response->getContent());
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $this->assertNull(DB::table('steam_auth_attempts')->value('consumed_at'));
        } finally {
            PersonalAccessToken::flushEventListeners();
        }
        $this->exchange($code)->assertOk();
    }

    public function test_failed_attempt_cannot_be_retried_with_a_new_assertion(): void
    {
        $this->fakeSteam('false');
        $attempt = $this->start();
        $this->completeCallback($this->assertion($attempt))->assertRedirect();
        $this->fakeSteam();
        $this->completeCallback($this->assertion($attempt))->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_account_disabled_before_exchange_cannot_receive_token(): void
    {
        $code = $this->code();
        Users::query()->update(['is_active' => false]);
        $this->exchange($code)->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_token_is_rejected_by_sanctum(): void
    {
        $token = $this->exchange($this->code())->assertOk()->json('token');
        Route::get('/_test/expiry', fn () => response()->noContent())->middleware('auth:sanctum');
        $this->travel(31)->days();
        $this->withToken($token)->getJson('/_test/expiry')->assertUnauthorized();
    }

    public function test_host_header_cannot_change_the_configured_callback(): void
    {
        $this->getJson('http://attacker.invalid/auth/steam/redirect?'.http_build_query([
            'redirect_uri' => self::RETURN_URI, 'state' => self::CLIENT_STATE,
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]))->assertStatus(400)->assertJsonPath('error', 'invalid_origin');
        $this->assertDatabaseCount('steam_auth_attempts', 0);
    }

    public function test_malformed_positive_verification_is_not_accepted(): void
    {
        $this->fakeSteam("true\nis_valid:false");
        $this->completeCallback($this->assertion($this->start()))
            ->assertRedirect(self::RETURN_URI.'?error=authentication_failed&state='.self::CLIENT_STATE);
        $this->assertDatabaseCount('users', 0);
    }
}
