<?php

namespace Tests\Feature;

use App\Models\Users;
use App\Models\UserStatSnapshots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\IsolatedDatabaseTestCase;

class PeriodStatsApiTest extends IsolatedDatabaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-10 12:00:00'));
    }

    private function snapshot(Users $user, string $date, int $factor = 1): void
    {
        $counts = [
            'kills' => 15, 'deaths' => 8, 'headshots' => 6,
            'matches' => 3, 'wins' => 2, 'losses' => 1,
            'rounds' => 30, 'mvps' => 2, 'bombs_planted' => 4, 'bombs_defused' => 1,
        ];
        UserStatSnapshots::create([
            'user_id' => $user->id, 'snapshot_date' => $date,
            ...array_map(fn ($value) => $value * $factor, $counts),
            // Deliberately unrelated lifetime metrics: the API must recalculate deltas.
            'kd_ratio' => 99, 'rating' => 99, 'win_rate' => 99,
        ]);
    }

    public function test_daily_uses_requested_day_and_recalculates_all_metrics(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-01');
        $this->snapshot($user, '2026-09-02', 2);
        $this->snapshot($user, '2026-09-03', 5);
        $this->snapshot(Users::factory()->create(), '2026-09-02', 20);

        $this->getJson("/api/player-stats/daily-snapshot/{$user->id}/2026-09-02")
            ->assertOk()->assertJsonPath('status', 'available')->assertJson(['daily_stats' => [
                'kills' => 15, 'deaths' => 8, 'headshots' => 6,
                'matches' => 3, 'wins' => 2, 'losses' => 1,
                'rounds' => 30, 'mvps' => 2, 'bombs_planted' => 4, 'bombs_defused' => 1,
                'kd_ratio' => 1.88, 'win_rate' => 66.67, 'headshot_percentage' => 40,
                'rating' => 0.62, 'impact_score' => 1.28, 'kdd' => 7,
            ]]);
    }

    public function test_invalid_dates_are_rejected_for_both_endpoints(): void
    {
        foreach (['daily', 'weekly'] as $period) {
            foreach (['2026-02-30', '02-09-2026', 'tomorrow'] as $date) {
                $this->getJson("/api/player-stats/{$period}-snapshot/1/{$date}")
                    ->assertUnprocessable()->assertJsonValidationErrors('date');
            }
        }
    }

    public function test_unknown_user_returns_404_for_both_endpoints(): void
    {
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/999/2026-09-02")->assertNotFound();
        }
    }

    public function test_daily_returns_latest_valid_day_when_requested_day_is_missing(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-01');
        $this->snapshot($user, '2026-09-02', 2);
        $this->getJson("/api/player-stats/daily-snapshot/{$user->id}/2026-09-03")
            ->assertOk()->assertJsonPath('status', 'fallback')
            ->assertJsonPath('daily_stats.kills', 15)->assertJsonPath('period.end_date', '2026-09-02');
    }

    public function test_daily_returns_lifetime_snapshot_when_no_comparison_is_possible(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-02');
        $this->getJson("/api/player-stats/daily-snapshot/{$user->id}/2026-09-02")
            ->assertOk()->assertJsonPath('status', 'insufficient_data')
            ->assertJsonPath('daily_stats', null)->assertJsonPath('period', null)
            ->assertJsonPath('latest_snapshot.kills', 15);
    }

    public function test_daily_does_not_label_multi_day_gaps_as_daily_stats(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-08-31');
        $this->snapshot($user, '2026-09-02', 2);
        $this->getJson("/api/player-stats/daily-snapshot/{$user->id}/2026-09-02")
            ->assertOk()->assertJsonPath('status', 'insufficient_data')
            ->assertJsonPath('daily_stats', null)->assertJsonPath('latest_snapshot.kills', 30);
    }

    public function test_unchanged_counters_produce_numeric_metrics_without_division_errors(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-01');
        $this->snapshot($user, '2026-09-02');
        foreach (['daily', 'weekly'] as $period) {
            $response = $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}/2026-09-02")->assertOk();
            foreach (['kd_ratio', 'win_rate', 'headshot_percentage', 'rating', 'kdd'] as $metric) {
                $response->assertJsonPath("{$period}_stats.{$metric}", 0);
            }
            $response->assertJsonPath("{$period}_stats.impact_score", 0.2);
        }
    }

    public function test_weekly_includes_sunday_to_saturday_and_excludes_other_weeks(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-08-29', 0);
        $this->snapshot($user, '2026-08-30');
        $this->snapshot($user, '2026-09-02', 2);
        $this->snapshot($user, '2026-09-05', 3);
        $this->snapshot($user, '2026-09-06', 10);
        $this->snapshot(Users::factory()->create(), '2026-09-05', 20);

        foreach (['2026-08-30', '2026-09-02', '2026-09-05'] as $date) {
            $this->getJson("/api/player-stats/weekly-snapshot/{$user->id}/{$date}")
                ->assertOk()->assertJsonPath('weekly_stats.kills', 30)
                ->assertJsonPath('weekly_stats.kd_ratio', 1.88)
                ->assertJsonPath('weekly_stats.rating', 0.62)
                ->assertJsonPath('weekly_stats.win_rate', 66.67)
                ->assertJsonPath('weekly_stats.headshot_percentage', 40)
                ->assertJsonPath('weekly_stats.impact_score', 1.28)
                ->assertJsonPath('weekly_stats.kdd', 14)
                ->assertJsonPath('period', [
                    'start_date' => '2026-08-30', 'end_date' => '2026-09-05', 'days_between' => 6,
                ]);
        }
    }

    public function test_weekly_defaults_to_current_week_and_reports_actual_coverage(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 5));
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-08-29', 0);
        $this->snapshot($user, '2026-09-01');
        $this->snapshot($user, '2026-09-04', 2);
        $this->getJson("/api/player-stats/weekly-snapshot/{$user->id}")
            ->assertOk()->assertJsonPath('weekly_stats.kills', 15)
            ->assertJsonPath('period', [
                'start_date' => '2026-09-01', 'end_date' => '2026-09-04', 'days_between' => 3,
            ]);
    }

    public function test_weekly_requires_two_snapshots_in_the_selected_week(): void
    {
        $user = Users::factory()->create();
        $url = "/api/player-stats/weekly-snapshot/{$user->id}/2026-09-02";
        $this->getJson($url)->assertOk()->assertJsonPath('weekly_stats', null);
        $this->snapshot($user, '2026-08-29');
        $this->snapshot($user, '2026-09-02', 2);
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'insufficient_data')
            ->assertJsonPath('weekly_stats', null)->assertJsonPath('latest_snapshot.kills', 30);
    }

    public function test_decreasing_counters_are_not_presented_as_period_stats(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-01', 2);
        $this->snapshot($user, '2026-09-02');
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}/2026-09-02")
                ->assertOk()->assertJsonPath('status', 'insufficient_data')->assertJsonPath($period.'_stats', null);
        }
    }

    public function test_daily_skips_recent_gap_and_returns_older_valid_pair(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-01');
        $this->snapshot($user, '2026-09-02', 2);
        $this->snapshot($user, '2026-09-09', 5);
        $this->getJson("/api/player-stats/daily-snapshot/{$user->id}")
            ->assertOk()->assertJsonPath('status', 'fallback')
            ->assertJsonPath('daily_stats.kills', 15)
            ->assertJsonPath('period.end_date', '2026-09-02')
            ->assertJsonPath('latest_snapshot.snapshot_date', '2026-09-09');
    }

    public function test_weekly_returns_previous_calculable_week_when_current_week_has_one_snapshot(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-08-25', 0);
        $this->snapshot($user, '2026-08-26');
        $this->snapshot($user, '2026-09-01', 2);
        $this->snapshot($user, '2026-09-05', 4);
        $this->snapshot($user, '2026-09-09', 8);
        $this->getJson("/api/player-stats/weekly-snapshot/{$user->id}")
            ->assertOk()->assertJsonPath('status', 'fallback')
            ->assertJsonPath('weekly_stats.kills', 30)
            ->assertJsonPath('period.start_date', '2026-09-01')
            ->assertJsonPath('period.end_date', '2026-09-05')
            ->assertJsonPath('requested_period.start_date', '2026-09-06');
    }

    public function test_empty_history_returns_a_successful_empty_state(): void
    {
        $user = Users::factory()->create();
        $this->snapshot(Users::factory()->create(), '2026-09-01');
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}")
                ->assertOk()->assertJsonPath('status', 'empty')
                ->assertJsonPath($period.'_stats', null)->assertJsonPath('latest_snapshot', null)
                ->assertJsonPath('period', null);
        }
    }

    public function test_historical_request_never_falls_forward_to_a_later_period(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-08');
        $this->snapshot($user, '2026-09-09', 2);
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}/2026-09-02")
                ->assertOk()->assertJsonPath('status', 'empty')->assertJsonPath('latest_snapshot', null);
        }
    }

    public function test_future_snapshots_are_not_used(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-11');
        $this->snapshot($user, '2026-09-12', 2);
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}")
                ->assertOk()->assertJsonPath('status', 'empty');
        }
    }

    public function test_valid_zero_activity_is_not_replaced_with_old_activity(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-01');
        $this->snapshot($user, '2026-09-02', 2);
        $this->snapshot($user, '2026-09-09', 2);
        $this->snapshot($user, '2026-09-10', 2);
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}")
                ->assertOk()->assertJsonPath('status', 'available')
                ->assertJsonPath($period.'_stats.kills', 0)->assertJsonPath('period.end_date', '2026-09-10');
        }
    }

    public function test_updated_snapshot_is_reflected_without_a_stale_derived_copy(): void
    {
        $user = Users::factory()->create();
        $this->snapshot($user, '2026-09-09');
        $this->snapshot($user, '2026-09-10', 2);
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}")
                ->assertOk()->assertJsonPath($period.'_stats.kills', 15);
        }
        UserStatSnapshots::where('user_id', $user->id)->whereDate('snapshot_date', '2026-09-10')->update(['kills' => 45]);
        foreach (['daily', 'weekly'] as $period) {
            $this->getJson("/api/player-stats/{$period}-snapshot/{$user->id}")
                ->assertOk()->assertJsonPath($period.'_stats.kills', 30);
        }
        $this->assertDatabaseCount('user_stat_snapshots', 2);
    }
}
