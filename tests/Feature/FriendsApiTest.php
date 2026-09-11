<?php

namespace Tests\Feature;

use App\Models\Users;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\IsolatedDatabaseTestCase;

class FriendsApiTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    private function login(): Users
    {
        $user = Users::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        Http::preventStrayRequests();

        return $user;
    }

    private function fake(array $ids, int $status = 200, bool $profiles = true): void
    {
        Http::fake([
            '*/GetFriendList/*' => Http::response(['friendslist' => ['friends' => array_map(fn ($id) => [
                'steamid' => $id, 'relationship' => 'friend', 'friend_since' => 1234567890,
            ], $ids)]], $status),
            '*/GetPlayerSummaries/*' => function ($request) use ($profiles) {
                return Http::response(['response' => ['players' => $profiles ? array_map(fn ($id) => [
                    'steamid' => $id, 'personaname' => 'Player '.$id,
                    'avatarfull' => 'https://avatars.steamstatic.com/test.jpg',
                    'profileurl' => 'https://steamcommunity.com/profiles/'.$id,
                ], explode(',', $request['steamids'])) : []]]);
            },
        ]);
    }

    public function test_lists_all_friends_and_identifies_members_without_creating_accounts(): void
    {
        $user = $this->login();
        $member = Users::factory()->create(['steam_id' => '76561198000000001']);
        $this->fake(['76561198000000002', $member->steam_id]);
        $this->getJson('/api/friends?steam_id=someone-else')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.killfeed_count', 1)
            ->assertJsonPath('data.0.user_id', $member->id)->assertJsonPath('data.0.uses_killfeed', true)
            ->assertJsonPath('data.1.user_id', null)->assertJsonPath('data.1.uses_killfeed', false)
            ->assertJsonPath('data.1.display_name', 'Player 76561198000000002');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'GetFriendList') && $request['steamid'] === $user->steam_id);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('friends', 0);
    }

    public function test_requires_authentication(): void
    {
        Http::fake();
        $this->getJson('/api/friends')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_empty_list_is_success_and_skips_profiles(): void
    {
        $this->login();
        $this->fake([]);
        $this->getJson('/api/friends')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        Http::assertSentCount(1);
    }

    public function test_private_list_is_not_reported_as_empty(): void
    {
        $this->login();
        $this->fake([], 401);
        $this->getJson('/api/friends')->assertForbidden()->assertJsonPath('error', 'steam_friends_unavailable');
    }

    public function test_network_failure_is_sanitized(): void
    {
        $this->login();
        Http::fake(['*' => Http::failedConnection()]);
        $response = $this->getJson('/api/friends')->assertStatus(503)->assertJsonPath('error', 'steam_unavailable');
        $this->assertStringNotContainsString('test-key', $response->getContent());
    }

    public function test_missing_profiles_do_not_remove_friends(): void
    {
        $this->login();
        $this->fake(['76561198000000002'], profiles: false);
        $this->getJson('/api/friends')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', null)->assertJsonPath('meta.profiles_complete', false);
    }

    public function test_batches_profiles_in_groups_of_at_most_100(): void
    {
        $this->login();
        $ids = array_map(fn ($i) => (string) (76561198000000000 + $i), range(1, 101));
        $this->fake($ids);
        $this->getJson('/api/friends')->assertOk()->assertJsonCount(101, 'data');
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => isset($request['steamids']) && count(explode(',', $request['steamids'])) > 100);
    }

    public function test_cached_steam_list_still_detects_new_killfeed_registrations(): void
    {
        $this->login();
        $this->fake(['76561198000000002']);
        $this->getJson('/api/friends')->assertOk()->assertJsonPath('data.0.uses_killfeed', false);
        Users::factory()->create(['steam_id' => '76561198000000002']);
        $this->getJson('/api/friends')->assertOk()->assertJsonPath('data.0.uses_killfeed', true);
        Http::assertSentCount(2);
    }

    public function test_inactive_users_and_duplicate_entries(): void
    {
        $user = $this->login();
        Users::factory()->create(['steam_id' => '76561198000000002', 'is_active' => false]);
        $this->fake([$user->steam_id, '76561198000000002', '76561198000000002']);
        $this->getJson('/api/friends')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uses_killfeed', false);
    }

    public function test_malformed_response_is_not_cached_as_empty(): void
    {
        $this->login();
        Http::fake(['*' => Http::response(['unexpected' => true])]);
        $this->getJson('/api/friends')->assertStatus(503);
        $this->getJson('/api/friends')->assertStatus(503);
        Http::assertSentCount(2);
    }

    public function test_sync_persists_only_active_killfeed_friends(): void
    {
        $user = $this->login();
        $member = Users::factory()->create(['steam_id' => '76561198000000001']);
        Users::factory()->create(['steam_id' => '76561198000000002', 'is_active' => false]);
        $this->fake([$member->steam_id, '76561198000000002', '76561198000000003']);

        $this->postJson('/api/friends/sync')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.killfeed_count', 1)
            ->assertJsonPath('meta.synced_count', 1)
            ->assertJsonPath('meta.friends_synced_at', fn ($value) => is_string($value));

        $this->assertDatabaseHas('friends', ['user_id' => $user->id, 'friend_id' => $member->id]);
        $this->assertDatabaseCount('friends', 1);
        $this->assertDatabaseCount('users', 3);
        $this->assertNotNull($user->fresh()->friends_synced_at);
    }

    public function test_sync_removes_friends_no_longer_returned_by_steam(): void
    {
        $user = $this->login();
        $kept = Users::factory()->create(['steam_id' => '76561198000000001']);
        $removed = Users::factory()->create(['steam_id' => '76561198000000002']);
        $user->friends()->attach([$kept->id, $removed->id]);
        $this->fake([$kept->steam_id]);

        $this->postJson('/api/friends/sync')->assertOk()->assertJsonPath('meta.synced_count', 1);

        $this->assertDatabaseHas('friends', ['user_id' => $user->id, 'friend_id' => $kept->id]);
        $this->assertDatabaseMissing('friends', ['user_id' => $user->id, 'friend_id' => $removed->id]);
    }

    public function test_empty_steam_list_clears_only_authenticated_users_outgoing_friends(): void
    {
        $user = $this->login();
        $friend = Users::factory()->create(['steam_id' => '76561198000000001']);
        $user->friends()->attach($friend->id);
        $friend->friends()->attach($user->id);
        $this->fake([]);

        $this->postJson('/api/friends/sync')->assertOk()->assertJsonPath('meta.synced_count', 0);

        $this->assertDatabaseMissing('friends', ['user_id' => $user->id, 'friend_id' => $friend->id]);
        $this->assertDatabaseHas('friends', ['user_id' => $friend->id, 'friend_id' => $user->id]);
    }

    public function test_failed_steam_sync_does_not_change_existing_friends(): void
    {
        $user = $this->login();
        $friend = Users::factory()->create(['steam_id' => '76561198000000001']);
        $user->friends()->attach($friend->id);
        $this->fake([], 401);

        $this->postJson('/api/friends/sync')->assertForbidden();

        $this->assertDatabaseHas('friends', ['user_id' => $user->id, 'friend_id' => $friend->id]);
        $this->assertNull($user->fresh()->friends_synced_at);
    }

    public function test_sync_requires_authentication(): void
    {
        Http::fake();
        $this->postJson('/api/friends/sync')->assertUnauthorized();
        Http::assertNothingSent();
    }
}
