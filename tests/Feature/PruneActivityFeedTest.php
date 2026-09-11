<?php

namespace Tests\Feature;

use App\Models\ActivityFeedItem;
use App\Models\Users;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\IsolatedDatabaseTestCase;

class PruneActivityFeedTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    public function test_command_removes_only_items_older_than_retention(): void
    {
        config(['activity_feed.retention_days' => 90]);
        $user = Users::factory()->create();
        foreach ([89, 91] as $days) {
            ActivityFeedItem::create([
                'actor_id' => $user->id, 'type' => 'test', 'payload' => [],
                'deduplication_key' => 'test:'.$days, 'occurred_at' => now()->subDays($days),
            ]);
        }

        $this->artisan('activity-feed:prune')->assertSuccessful();

        $this->assertDatabaseHas('activity_feed_items', ['deduplication_key' => 'test:89']);
        $this->assertDatabaseMissing('activity_feed_items', ['deduplication_key' => 'test:91']);
    }
}
