<?php

namespace Tests\Feature;

use App\Models\ActivityFeedItem;
use App\Models\Users;
use Database\Seeders\ActivityFeedDemoCleanupSeeder;
use Database\Seeders\ActivityFeedDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\IsolatedDatabaseTestCase;

class ActivityFeedDemoSeederTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_is_idempotent_and_cleanup_preserves_real_activities(): void
    {
        $owner = Users::factory()->create();
        $this->seed(ActivityFeedDemoSeeder::class);
        $this->seed(ActivityFeedDemoSeeder::class);

        $this->assertSame(32, ActivityFeedItem::where('deduplication_key', 'like', ActivityFeedDemoSeeder::KEY_PREFIX.'%')->count());
        foreach (['daily_kills_milestone', 'daily_negative_kd', 'weekly_headshots_milestone', 'weekly_rating_highlight'] as $type) {
            $this->assertSame(8, ActivityFeedItem::where('type', $type)->count());
        }

        ActivityFeedItem::create([
            'actor_id' => $owner->id,
            'type' => 'badge_earned',
            'payload' => ['badge' => ['key' => 'real']],
            'deduplication_key' => 'real-activity',
            'occurred_at' => now(),
        ]);
        $this->seed(ActivityFeedDemoCleanupSeeder::class);

        $this->assertDatabaseCount('activity_feed_items', 1);
        $this->assertDatabaseHas('activity_feed_items', ['deduplication_key' => 'real-activity']);
    }
}
