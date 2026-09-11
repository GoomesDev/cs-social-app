<?php

namespace Tests\Feature;

use App\Models\Users;
use Database\Seeders\GroupTestingCleanupSeeder;
use Database\Seeders\GroupTestingDemoSeeder;
use Database\Seeders\GroupTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\IsolatedDatabaseTestCase;

class GroupTestingSeederTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    public function test_seeder_is_repeatable_and_cleanup_only_removes_test_users(): void
    {
        $owner = Users::factory()->create();

        $this->seed(GroupTestingDemoSeeder::class);
        $this->seed(GroupTestingSeeder::class);

        $this->assertDatabaseCount('users', 9);
        $this->assertDatabaseCount('friends', 16);
        $this->assertSame(16, \Illuminate\Support\Facades\DB::table('friends')->where('is_test_data', true)->count());
        $this->assertSame(8, $owner->friends()->count());
        $this->assertDatabaseCount('user_stat_snapshots', 120);

        $testUser = Users::where('username', GroupTestingSeeder::USERNAME_PREFIX.'01')->firstOrFail();
        $this->getJson('/api/get-profile/'.$testUser->id)->assertOk()
            ->assertJsonPath('display_name', 'Clutch Cobra')
            ->assertJsonPath('profile_url', 'https://steamcommunity.com/profiles/76561199990000001');
        $this->getJson('/api/player-stats/daily-snapshot/'.$testUser->id)->assertOk()
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('daily_stats', fn ($stats) => is_array($stats) && $stats['matches'] > 0);
        $this->getJson('/api/player-stats/weekly-snapshot/'.$testUser->id)->assertOk()
            ->assertJsonPath('status', fn ($status) => in_array($status, ['available', 'fallback'], true))
            ->assertJsonPath('weekly_stats', fn ($stats) => is_array($stats) && $stats['matches'] > 0);

        $this->seed(GroupTestingCleanupSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseCount('friends', 0);
        $this->assertDatabaseCount('user_stat_snapshots', 0);
    }
}
