<?php

namespace Tests\Feature;

use App\Models\ActivityFeedItem;
use App\Models\Users;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\IsolatedDatabaseTestCase;

class FeedApiTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    private function login(): Users
    {
        $user = Users::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);

        return $user;
    }

    private function activity(Users $actor, string $type = 'daily_kills_milestone', ?string $time = null, array $payload = ['kills' => 50]): ActivityFeedItem
    {
        return ActivityFeedItem::create([
            'actor_id' => $actor->id, 'type' => $type, 'period' => 'daily',
            'reference_date' => '2026-09-11', 'payload' => $payload,
            'deduplication_key' => uniqid($type.':', true),
            'occurred_at' => $time ?? now(),
        ]);
    }

    public function test_feed_requires_authentication(): void
    {
        $this->getJson('/api/feed')->assertUnauthorized();
    }

    public function test_feed_contains_self_and_active_friends_without_steam_id(): void
    {
        $user = $this->login();
        $friend = Users::factory()->create();
        $stranger = Users::factory()->create();
        $inactive = Users::factory()->create(['is_active' => false]);
        $user->friends()->attach([$friend->id, $inactive->id]);
        $this->activity($user);
        $this->activity($friend, 'weekly_rating_highlight');
        $this->activity($stranger);
        $this->activity($inactive);

        $response = $this->getJson('/api/feed')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.per_page', 20);
        $this->assertStringNotContainsString('steam_id', $response->getContent());

        $user->friends()->detach($friend->id);
        $this->getJson('/api/feed')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_feed_is_paginated_and_ordered_newest_first(): void
    {
        $user = $this->login();
        foreach (range(1, 21) as $minute) {
            $this->activity($user, time: now()->subMinutes($minute));
        }

        $this->getJson('/api/feed')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 21)->assertJsonPath('links.prev', null)
            ->assertJsonPath('data.0.occurred_at', now()->subMinute()->toIso8601String());
        $this->getJson('/api/feed?page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('links.next', null);
    }

    public function test_contract_accepts_future_badge_activity(): void
    {
        $user = $this->login();
        $this->activity($user, 'badge_earned', payload: ['badge' => [
            'id' => 5, 'key' => 'clutch_master', 'name' => 'Mestre do Clutch',
            'icon' => 'award', 'rarity' => 'epic',
        ]]);

        $this->getJson('/api/feed')->assertOk()->assertJsonPath('data.0.type', 'badge_earned')
            ->assertJsonPath('data.0.payload.badge.key', 'clutch_master');
    }
}
