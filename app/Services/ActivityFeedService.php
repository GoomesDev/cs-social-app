<?php

namespace App\Services;

use App\Models\ActivityFeedItem;
use App\Models\Users;
use Carbon\CarbonImmutable;

class ActivityFeedService
{
    public function __construct(private readonly PeriodStats $periodStats) {}

    public function evaluateCurrent(Users $user, ?CarbonImmutable $reference = null): void
    {
        $reference ??= CarbonImmutable::today();
        $daily = $this->periodStats->forUser($user->id, $reference, 'daily');
        $weekly = $this->periodStats->forUser($user->id, $reference, 'weekly');

        if ($daily['status'] === 'available' && is_array($daily['daily_stats'])) {
            $this->evaluateDaily($user, $reference, $daily['daily_stats']);
        }
        if ($weekly['status'] === 'available' && is_array($weekly['weekly_stats'])) {
            $weekStart = $reference->startOfWeek(CarbonImmutable::SUNDAY);
            $this->evaluateWeekly($user, $weekStart, $weekly['weekly_stats']);
        }
    }

    private function evaluateDaily(Users $user, CarbonImmutable $date, array $stats): void
    {
        $milestone = $this->highestReached((int) $stats['kills'], config('activity_feed.daily_kill_milestones', []));
        if ($milestone !== null) {
            $this->create($user, 'daily_kills_milestone', 'daily', $date, [
                'kills' => (int) $stats['kills'],
                'milestone' => $milestone,
                'matches' => (int) $stats['matches'],
            ], (string) $milestone);
        }

        $difference = (int) $stats['deaths'] - (int) $stats['kills'];
        if ((int) $stats['matches'] > 0 && $difference >= (int) config('activity_feed.daily_negative_kd_difference', 10)) {
            $this->create($user, 'daily_negative_kd', 'daily', $date, [
                'kills' => (int) $stats['kills'],
                'deaths' => (int) $stats['deaths'],
                'difference' => $difference,
                'matches' => (int) $stats['matches'],
            ]);
        }
    }

    private function evaluateWeekly(Users $user, CarbonImmutable $weekStart, array $stats): void
    {
        $milestone = $this->highestReached((int) $stats['headshots'], config('activity_feed.weekly_headshot_milestones', []));
        if ($milestone !== null) {
            $this->create($user, 'weekly_headshots_milestone', 'weekly', $weekStart, [
                'headshots' => (int) $stats['headshots'],
                'milestone' => $milestone,
                'headshot_percentage' => (float) $stats['headshot_percentage'],
            ], (string) $milestone);
        }

        if ((int) $stats['matches'] >= (int) config('activity_feed.weekly_rating_minimum_matches', 3)
            && (float) $stats['rating'] >= (float) config('activity_feed.weekly_rating_minimum', 1.20)) {
            $this->create($user, 'weekly_rating_highlight', 'weekly', $weekStart, [
                'rating' => (float) $stats['rating'],
                'matches' => (int) $stats['matches'],
                'kills' => (int) $stats['kills'],
                'deaths' => (int) $stats['deaths'],
            ]);
        }
    }

    private function highestReached(int $value, array $milestones): ?int
    {
        $reached = array_values(array_filter($milestones, fn ($milestone) => is_int($milestone) && $milestone <= $value));

        return $reached === [] ? null : max($reached);
    }

    private function create(Users $user, string $type, string $period, CarbonImmutable $reference, array $payload, string $variant = 'once'): void
    {
        $key = implode(':', [$type, $user->id, $reference->toDateString(), $variant]);

        ActivityFeedItem::query()->insertOrIgnore([
            'actor_id' => $user->id,
            'type' => $type,
            'period' => $period,
            'reference_date' => $reference->toDateString(),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'deduplication_key' => $key,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
