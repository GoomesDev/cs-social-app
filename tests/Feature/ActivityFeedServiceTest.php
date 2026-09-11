<?php

namespace Tests\Feature;

use App\Models\ActivityFeedItem;
use App\Models\Users;
use App\Models\UserStatSnapshots;
use App\Services\ActivityFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\IsolatedDatabaseTestCase;

class ActivityFeedServiceTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-11 20:30:00'));
    }

    private function snapshot(Users $user, string $date, array $values): UserStatSnapshots
    {
        return UserStatSnapshots::updateOrCreate(['user_id' => $user->id, 'snapshot_date' => $date], array_replace([
            'matches' => 0, 'wins' => 0, 'losses' => 0, 'rounds' => 0, 'kills' => 0, 'deaths' => 0,
            'mvps' => 0, 'bombs_planted' => 0, 'bombs_defused' => 0, 'headshots' => 0,
            'headshot_percentage' => 0, 'kd_ratio' => 0, 'rating' => 0, 'win_rate' => 0,
            'impact_score' => 0, 'kdd' => 0,
        ], $values));
    }

    public function test_daily_rules_emit_exact_threshold_payloads_once(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-10', []);
        $this->snapshot($user, '2026-09-11', [
            'matches' => 4, 'wins' => 1, 'losses' => 3, 'rounds' => 70,
            'kills' => 50, 'deaths' => 60, 'headshots' => 20,
        ]);

        $service = app(ActivityFeedService::class);
        $service->evaluateCurrent($user);
        $service->evaluateCurrent($user);

        $this->assertDatabaseCount('activity_feed_items', 2);
        $kills = ActivityFeedItem::where('type', 'daily_kills_milestone')->firstOrFail();
        $this->assertSame(['kills' => 50, 'milestone' => 50, 'matches' => 4], $kills->payload);
        $negative = ActivityFeedItem::where('type', 'daily_negative_kd')->firstOrFail();
        $this->assertSame(['kills' => 50, 'deaths' => 60, 'difference' => 10, 'matches' => 4], $negative->payload);
    }

    public function test_higher_daily_and_weekly_milestones_create_new_events(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-10', []);
        $today = $this->snapshot($user, '2026-09-11', [
            'matches' => 7, 'wins' => 5, 'losses' => 2, 'rounds' => 70,
            'kills' => 60, 'deaths' => 35, 'headshots' => 25, 'mvps' => 5,
        ]);
        $service = app(ActivityFeedService::class);
        $service->evaluateCurrent($user);

        $today->update(['kills' => 110, 'headshots' => 55]);
        $service->evaluateCurrent($user);

        $this->assertSame([50, 100], ActivityFeedItem::where('type', 'daily_kills_milestone')
            ->orderBy('id')->get()->pluck('payload')->pluck('milestone')->all());
        $this->assertSame([25, 50], ActivityFeedItem::where('type', 'weekly_headshots_milestone')
            ->orderBy('id')->get()->pluck('payload')->pluck('milestone')->all());
    }

    public function test_weekly_rating_uses_configured_threshold_and_structured_payload(): void
    {
        config(['activity_feed.weekly_rating_minimum' => 1.20, 'activity_feed.weekly_rating_minimum_matches' => 3]);
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-07', []);
        $this->snapshot($user, '2026-09-11', [
            'matches' => 7, 'wins' => 5, 'losses' => 2, 'rounds' => 70,
            'kills' => 120, 'deaths' => 40, 'headshots' => 45, 'mvps' => 7,
        ]);

        app(ActivityFeedService::class)->evaluateCurrent($user);

        $activity = ActivityFeedItem::where('type', 'weekly_rating_highlight')->firstOrFail();
        $this->assertGreaterThanOrEqual(1.20, $activity->payload['rating']);
        $this->assertSame(7, $activity->payload['matches']);
        $this->assertSame(120, $activity->payload['kills']);
        $this->assertSame(40, $activity->payload['deaths']);
    }

    public function test_insufficient_and_fallback_periods_do_not_generate_activities(): void
    {
        $insufficient = Users::factory()->create();
        $this->snapshot($insufficient, '2026-09-11', ['kills' => 200, 'matches' => 10]);
        app(ActivityFeedService::class)->evaluateCurrent($insufficient);

        $fallback = Users::factory()->create();
        $this->snapshot($fallback, '2026-09-01', []);
        $this->snapshot($fallback, '2026-09-02', ['kills' => 200, 'matches' => 10]);
        app(ActivityFeedService::class)->evaluateCurrent($fallback);

        $this->assertDatabaseCount('activity_feed_items', 0);
    }

    public function test_rating_below_configured_minimum_is_not_basic_feed_activity(): void
    {
        config(['activity_feed.weekly_rating_minimum' => 1.20]);
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-07', []);
        $this->snapshot($user, '2026-09-11', [
            'matches' => 3, 'wins' => 1, 'losses' => 2, 'rounds' => 60,
            'kills' => 45, 'deaths' => 40, 'headshots' => 10,
        ]);

        app(ActivityFeedService::class)->evaluateCurrent($user);

        $this->assertDatabaseMissing('activity_feed_items', ['type' => 'weekly_rating_highlight']);
    }

    public function test_steam_snapshot_sync_evaluates_current_activities(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-10', []);
        Http::fake(['*' => Http::response(['playerstats' => ['stats' => [
            ['name' => 'total_kills', 'value' => 55],
            ['name' => 'total_deaths', 'value' => 30],
            ['name' => 'total_matches_won', 'value' => 3],
            ['name' => 'total_matches_played', 'value' => 5],
            ['name' => 'total_kills_headshot', 'value' => 25],
            ['name' => 'total_mvps', 'value' => 4],
            ['name' => 'total_rounds_played', 'value' => 80],
        ]]])]);

        $this->getJson('/api/player-stats/'.$user->id)->assertOk()
            ->assertJsonPath('message', 'Snapshot criado com sucesso');

        $this->assertDatabaseHas('activity_feed_items', [
            'actor_id' => $user->id,
            'type' => 'daily_kills_milestone',
            'reference_date' => '2026-09-11',
        ]);
    }
}
